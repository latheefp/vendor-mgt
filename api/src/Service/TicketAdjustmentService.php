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
 * once and everything downstream — the vendor invoice, the technician
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
     * ledger, negative reduces it — "-150" on `vendor_receivable` is a
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
            'vendor_agreement_id' => $ticket->vendor_agreement_id,
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
                'ledger_label' => $ledger->label(),
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

        $margin = $byLedger[Ledger::VendorReceivable->value]
            ->plus($byLedger[Ledger::CustomerCollection->value])
            ->minus($byLedger[Ledger::VendorPayable->value])
            ->minus($byLedger[Ledger::TechnicianPayable->value]);

        return [
            'lines' => $lines,
            'totals' => [
                'vendor_receivable' => $byLedger[Ledger::VendorReceivable->value]->jsonSerialize(),
                'customer_collection' => $byLedger[Ledger::CustomerCollection->value]->jsonSerialize(),
                'vendor_payable' => $byLedger[Ledger::VendorPayable->value]->jsonSerialize(),
                'technician_payable' => $byLedger[Ledger::TechnicianPayable->value]->jsonSerialize(),
                'gross_margin' => $margin->jsonSerialize(),
                'line_count' => count($lines),
            ],
            'is_frozen' => $allFrozen,
        ];
    }
}
