<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\Charge\ChargeBuilder;
use App\Domain\Enum\ChargeLineType;
use App\Domain\Enum\Ledger;
use App\Domain\Money;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use InvalidArgumentException;

/**
 * Correcting a ticket's money after it has been frozen.
 *
 * A frozen line is never edited. `TicketClosureService` writes the ledger
 * once and everything downstream — the company invoice, the technician
 * payout, every margin report — reads those rows verbatim, so editing one
 * would silently rewrite a document already sent to a company. The whole
 * point of freezing is that the number on the invoice they are holding is
 * still the number in our database.
 *
 * So corrections append. An adjustment is a new row on a named ledger,
 * with a mandatory reason and the user who authorised it, left at
 * `settlement_status = 'open'` so the next invoice or payout run picks it
 * up on its own. The original line stays exactly as it was, and the pair
 * of them together is the audit trail: what we charged, what we corrected,
 * and why.
 */
class TicketAdjustmentService
{
    use LocatorAwareTrait;

    public function __construct(
        private readonly TicketWorkflow $workflow = new TicketWorkflow(),
    ) {
    }

    /**
     * Append a correction to a frozen ticket.
     *
     * `amount` is a signed rupee string. Positive increases the named
     * ledger, negative reduces it — "-150" on `company_receivable` is a
     * Rs.150 credit to the company, which is what a conceded dispute
     * actually is. Both directions are legitimate and the sign is the only
     * thing that distinguishes them, so it is taken from the caller rather
     * than inferred from a separate flag nobody would set consistently.
     *
     * @param array<string, mixed> $data
     * @return array{ok: true, charge_id: int, line: array<string, mixed>, totals: array<string, mixed>}
     *        |array{ok: false, code: string, errors: array<string, list<string>>}
     */
    public function add(int $ticketId, array $data, ?int $actorUserId = null): array
    {
        try {
            $ticket = $this->fetchTable('Tickets')->get($ticketId);
        } catch (RecordNotFoundException) {
            return [
                'ok' => false,
                'code' => 'not_found',
                'errors' => ['ticket_id' => ['No such ticket.']],
            ];
        }

        // Before the freeze there is nothing to correct: closing the ticket
        // recomputes from scratch, and an adjustment written now would be
        // deleted by that run. Sending the desk to the right tool is more
        // useful than accepting a row that quietly disappears.
        if ($ticket->charges_frozen_at === null) {
            return [
                'ok' => false,
                'code' => 'not_frozen',
                'errors' => ['ticket' => [
                    'This ticket has no frozen charges yet, so there is nothing '
                    . 'to adjust. Close it — or re-close it with a manual amount '
                    . 'if the rate card cannot price it.',
                ]],
            ];
        }

        $ledger = Ledger::tryFrom(trim((string)($data['ledger'] ?? '')));
        if ($ledger === null) {
            return [
                'ok' => false,
                'code' => 'validation_error',
                'errors' => ['ledger' => [sprintf(
                    'Choose which book this correction lands on: %s.',
                    implode(', ', array_column(Ledger::cases(), 'value')),
                )]],
            ];
        }

        $reason = trim((string)($data['reason'] ?? ''));
        if ($reason === '') {
            return [
                'ok' => false,
                'code' => 'validation_error',
                'errors' => ['reason' => [
                    'An adjustment needs a reason. Without one it is '
                    . 'indistinguishable from a mistake when it surfaces in an audit.',
                ]],
            ];
        }

        $raw = $data['amount'] ?? '';
        if ($raw === '' || $raw === null) {
            return [
                'ok' => false,
                'code' => 'validation_error',
                'errors' => ['amount' => ['How much? Use a negative amount to reduce the ledger.']],
            ];
        }

        try {
            $amount = Money::parse((string)$raw);
        } catch (InvalidArgumentException $e) {
            return [
                'ok' => false,
                'code' => 'validation_error',
                'errors' => ['amount' => [$e->getMessage()]],
            ];
        }

        if ($amount->isZero()) {
            return [
                'ok' => false,
                'code' => 'validation_error',
                'errors' => ['amount' => ['A zero adjustment changes nothing.']],
            ];
        }

        $line = (new ChargeBuilder())->adjustment($ledger, $amount, $reason, $actorUserId);

        $charges = $this->fetchTable('TicketCharges');
        $row = $line->toRow() + [
            'ticket_id' => $ticketId,
            // Carried from the ticket so the correction is attributable to
            // the same card and agreement the original lines were priced
            // under, not to whatever is active today.
            'rate_card_id' => $ticket->rate_card_id,
            'company_agreement_id' => $ticket->company_agreement_id,
            'computed_at' => DateTime::now(),
            'computed_by_user_id' => $actorUserId,
            // Frozen on arrival: a re-close must never delete it, and the
            // invoice run only reads frozen rows.
            'is_frozen' => true,
            'settlement_status' => 'open',
            'notes' => $data['notes'] ?? null,
        ];

        $entity = $charges->newEntity($row);
        if (!$charges->save($entity)) {
            return ['ok' => false, 'code' => 'validation_error', 'errors' => $entity->getErrors()];
        }

        $this->workflow->logEvent(
            $ticketId,
            'adjustment_added',
            null,
            null,
            $actorUserId,
            sprintf('%s adjustment on %s: %s', $amount->format(), $ledger->label(), $reason),
            [
                'ticket_charge_id' => (int)$entity->id,
                'ledger' => $ledger->value,
                'amount_paise' => $amount->paise,
                'reason' => $reason,
            ],
        );

        return [
            'ok' => true,
            'charge_id' => (int)$entity->id,
            'line' => [
                'type' => $line->type->value,
                'ledger' => $line->ledger->value,
                'description' => $line->description,
                'amount' => $line->amount->jsonSerialize(),
            ],
            'totals' => $this->ledger($ticketId)['totals'],
        ];
    }

    /**
     * Record additional service agreed on an open job — a BOQ line.
     *
     * The mirror image of add(): that one refuses an unfrozen ticket, this
     * one refuses a frozen one. Once the bill has gone out, extra work is
     * no longer part of the original quote and has to be an adjustment
     * carrying a reason, so the desk is sent there instead.
     *
     * Unlike an adjustment, this is not a free-form amount: it must name a
     * `rate_card_item_id` on the company's own active rate card, so what
     * gets billed for a service always traces back to a price the company
     * agreed to, not a figure typed in the moment.
     *
     * The line is written unfrozen. Closure prices the ticket and then
     * freezes it alongside the computed lines rather than recomputing it
     * away — see TicketClosureService::freeze().
     *
     * @param array<string, mixed> $data
     * @return array{ok: true, charge_id: int, line: array<string, mixed>, totals: array<string, mixed>}
     *        |array{ok: false, code: string, errors: array<string, list<string>>}
     */
    public function addServiceLine(int $ticketId, array $data, ?int $actorUserId = null): array
    {
        try {
            $ticket = $this->fetchTable('Tickets')->get($ticketId);
        } catch (RecordNotFoundException) {
            return [
                'ok' => false,
                'code' => 'not_found',
                'errors' => ['ticket_id' => ['No such ticket.']],
            ];
        }

        if ($ticket->charges_frozen_at !== null) {
            return [
                'ok' => false,
                'code' => 'frozen',
                'errors' => ['ticket' => [
                    'This ticket is closed and its charges are frozen, so extra '
                    . 'work cannot be added to the original bill. Raise an '
                    . 'adjustment instead.',
                ]],
            ];
        }

        $ledger = Ledger::tryFrom(trim((string)($data['ledger'] ?? '')));
        if ($ledger === null) {
            return [
                'ok' => false,
                'code' => 'validation_error',
                'errors' => ['ledger' => [sprintf(
                    'Choose which book this service lands on: %s.',
                    implode(', ', array_column(Ledger::cases(), 'value')),
                )]],
            ];
        }

        $rateCardItemId = (int)($data['rate_card_item_id'] ?? 0);
        if ($rateCardItemId <= 0) {
            return [
                'ok' => false,
                'code' => 'validation_error',
                'errors' => ['rate_card_item_id' => [
                    'Pick a service from the company\'s active rate card.',
                ]],
            ];
        }

        $item = $this->fetchTable('RateCardItems')->find()
            ->where(['RateCardItems.id' => $rateCardItemId, 'RateCardItems.is_active' => true])
            ->innerJoinWith('RateCards', function ($q) use ($ticket) {
                return $q->where([
                    'RateCards.company_id' => $ticket->company_id,
                    'RateCards.status' => 'active',
                ]);
            })
            ->contain(['JobTypes'])
            ->first();

        if ($item === null) {
            return [
                'ok' => false,
                'code' => 'validation_error',
                'errors' => ['rate_card_item_id' => [
                    'That item is not on this company\'s active rate card.',
                ]],
            ];
        }

        $description = $item->label !== '' ? $item->label : $item->job_type->name;
        $amount = Money::fromPaise((int)$item->amount_paise);

        if ($amount->isZero()) {
            return [
                'ok' => false,
                'code' => 'validation_error',
                'errors' => ['amount' => ['A zero service line bills nothing.']],
            ];
        }

        $line = (new ChargeBuilder())->boqLine(
            $ledger,
            $amount,
            $description,
            $actorUserId,
            ['rate_card_item_id' => $item->id],
        );

        $charges = $this->fetchTable('TicketCharges');
        $row = $line->toRow() + [
            'ticket_id' => $ticketId,
            // The card itself is left null until closure stamps the card
            // and agreement the whole ticket is priced under — binding
            // today's card to a job that closes next week would attribute
            // it to the wrong terms. The item is pinned, though: it names
            // the exact priced line the desk picked, whichever card wins.
            'rate_card_id' => null,
            'company_agreement_id' => null,
            'computed_at' => DateTime::now(),
            'computed_by_user_id' => $actorUserId,
            'is_frozen' => false,
            'settlement_status' => 'open',
            'notes' => $data['notes'] ?? null,
        ];

        $entity = $charges->newEntity($row);
        if (!$charges->save($entity)) {
            return ['ok' => false, 'code' => 'validation_error', 'errors' => $entity->getErrors()];
        }

        $this->workflow->logEvent(
            $ticketId,
            'boq_line_added',
            null,
            null,
            $actorUserId,
            sprintf('%s service on %s: %s', $amount->format(), $ledger->label(), $description),
            [
                'ticket_charge_id' => (int)$entity->id,
                'ledger' => $ledger->value,
                'amount_paise' => $amount->paise,
                'description' => $description,
                'rate_card_item_id' => $item->id,
            ],
        );

        return [
            'ok' => true,
            'charge_id' => (int)$entity->id,
            'line' => [
                'type' => $line->type->value,
                'ledger' => $line->ledger->value,
                'description' => $line->description,
                'amount' => $line->amount->jsonSerialize(),
            ],
            'totals' => $this->ledger($ticketId)['totals'],
        ];
    }

    /**
     * The ticket's frozen ledger as it stands, adjustments included.
     *
     * The frozen rows were previously only reachable by loading the whole
     * ticket, which made "what does this job actually owe now, after two
     * corrections?" a client-side sum. Totalling it here keeps that answer
     * in one place, next to the code that writes the rows.
     *
     * @return array{lines: list<array<string, mixed>>, totals: array<string, mixed>, is_frozen: bool}
     */
    public function ledger(int $ticketId): array
    {
        $rows = $this->fetchTable('TicketCharges')->find()
            ->where(['ticket_id' => $ticketId])
            ->orderByAsc('id')
            ->disableHydration()
            ->all()
            ->toList();

        // Named on every line below rather than left as "the company".
        // This sheet gets forwarded and printed, and by then the reader
        // has lost the context that would tell them which company.
        // Aliased, so the row comes back flat. Selecting `Companies.name`
        // unaliased buries it under `_matchingData`, which reads like a
        // missing name rather than a nested one.
        $companyName = $this->fetchTable('Tickets')->find()
            ->select(['company_name' => 'Companies.name'])
            ->innerJoinWith('Companies')
            ->where(['Tickets.id' => $ticketId])
            ->disableHydration()
            ->first()['company_name'] ?? null;

        $byLedger = [];
        foreach (Ledger::cases() as $case) {
            $byLedger[$case->value] = Money::zero();
        }

        $lines = [];
        $allFrozen = $rows !== [];

        foreach ($rows as $row) {
            $amount = Money::fromPaise((int)$row['amount_paise']);
            $ledger = Ledger::from((string)$row['ledger']);
            $type = ChargeLineType::tryFrom((string)$row['line_type']);
            $allFrozen = $allFrozen && (bool)$row['is_frozen'];

            // Normally decoded by the column's json type, but a raw string
            // here would turn a display flag into a fatal offset-on-string.
            $snapshot = $row['calc_snapshot'];
            if (is_string($snapshot)) {
                $snapshot = json_decode($snapshot, true);
            }
            $snapshot = is_array($snapshot) ? $snapshot : [];

            $byLedger[$ledger->value] = $byLedger[$ledger->value]->plus($amount);

            $lines[] = [
                'id' => (int)$row['id'],
                'type' => $row['line_type'],
                'type_label' => $type?->label(),
                'ledger' => $ledger->value,
                'ledger_label' => $ledger->labelFor($companyName),
                'description' => $row['description'],
                'quantity' => $row['quantity'],
                'amount' => $amount->jsonSerialize(),
                'is_adjustment' => $type === ChargeLineType::Adjustment,
                // Whether the base line came off the card or was agreed by
                // hand, surfaced so the desk sees it without opening JSON.
                'is_manual_base' => $type === ChargeLineType::Base
                    && ($snapshot['override']['manual'] ?? false) === true,
                'settlement_status' => $row['settlement_status'],
                'computed_at' => $row['computed_at'],
                'snapshot' => $snapshot,
            ];
        }

        $margin = $byLedger[Ledger::CompanyReceivable->value]
            ->plus($byLedger[Ledger::CustomerCollection->value])
            ->minus($byLedger[Ledger::CompanyPayable->value])
            ->minus($byLedger[Ledger::TechnicianPayable->value]);

        return [
            'lines' => $lines,
            'totals' => [
                'company_receivable' => $byLedger[Ledger::CompanyReceivable->value]->jsonSerialize(),
                'customer_collection' => $byLedger[Ledger::CustomerCollection->value]->jsonSerialize(),
                'company_payable' => $byLedger[Ledger::CompanyPayable->value]->jsonSerialize(),
                'technician_payable' => $byLedger[Ledger::TechnicianPayable->value]->jsonSerialize(),
                'gross_margin' => $margin->jsonSerialize(),
                'line_count' => count($lines),
            ],
            'is_frozen' => $allFrozen,
            'freeze' => $this->freezeContext($ticketId),
        ];
    }

    /**
     * When the charges were frozen, by whom and why — or null if they are
     * still open.
     *
     * A frozen ticket refuses parts and refuses closure, and until this
     * existed the UI could only report *that* it had been refused, never
     * why. That turned an ordinary "somebody closed it an hour ago" into a
     * database query. The freeze timestamp is the authority on whether the
     * ledger is locked; the event supplies the story behind it.
     *
     * @return array{frozen_at: string, actor: string|null, reason: string|null}|null
     */
    private function freezeContext(int $ticketId): ?array
    {
        $ticket = $this->fetchTable('Tickets')->find()
            ->select(['charges_frozen_at'])
            ->where(['id' => $ticketId])
            ->disableHydration()
            ->first();

        if ($ticket === null || $ticket['charges_frozen_at'] === null) {
            return null;
        }

        // Newest wins: a ticket frozen, unpicked and refrozen should read as
        // the freeze currently in force, not the first one ever recorded.
        $event = $this->fetchTable('TicketEvents')->find()
            ->select([
                'description' => 'TicketEvents.description',
                'actor' => 'ActorUsers.name',
            ])
            ->leftJoinWith('ActorUsers')
            ->where([
                'TicketEvents.ticket_id' => $ticketId,
                'TicketEvents.event_type' => 'charges_frozen',
            ])
            ->orderByDesc('TicketEvents.occurred_at')
            ->disableHydration()
            ->first();

        return [
            'frozen_at' => (string)$ticket['charges_frozen_at'],
            'actor' => $event['actor'] ?? null,
            'reason' => $event['description'] ?? null,
        ];
    }

    /**
     * Remove a charge or BOQ line from a ticket.
     */
    public function remove(int $ticketChargeId, ?int $actorUserId = null): array
    {
        $chargesTable = $this->fetchTable('TicketCharges');
        $charge = $chargesTable->find()->where(['id' => $ticketChargeId])->first();

        if ($charge === null) {
            return ['ok' => false, 'code' => 'not_found', 'errors' => ['charge' => ['Charge line not found.']]];
        }

        if ($charge->settlement_status === 'settled') {
            return ['ok' => false, 'code' => 'settled', 'errors' => ['charge' => ['This charge line is already settled and cannot be removed.']]];
        }

        $ticketId = (int)$charge->ticket_id;
        $description = (string)$charge->description;

        $chargesTable->delete($charge);

        $this->workflow->logEvent(
            $ticketId,
            'charge_removed',
            null,
            null,
            $actorUserId,
            sprintf('Removed charge line: %s', $description),
        );

        return ['ok' => true, 'ticket_id' => $ticketId];
    }
}
