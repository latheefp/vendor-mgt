<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\Charge\BaseOverride;
use App\Domain\Charge\ChargeBuilder;
use App\Domain\Charge\ChargeLine;
use App\Domain\Charge\ChargeSet;
use App\Domain\Company\SettingCatalog;
use App\Domain\Charge\SpareUsage;
use App\Domain\Enum\ChargeLineType;
use App\Domain\Enum\Payer;
use App\Domain\Enum\WarrantyScope;
use App\Domain\Exception\RateNotFoundException;
use App\Domain\Exception\UnrateableTicketException;
use App\Domain\Money;
use App\Domain\Payout\PayoutBuilder;
use App\Domain\Rate\RateContext;
use App\Domain\Rate\RateResolver;
use App\Domain\Sla\HoldPeriod;
use App\Domain\Sla\SlaCalculator;
use App\Domain\Sla\SlaEvaluator;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Closing a ticket and freezing what it earned.
 *
 * This is the only place that writes `ticket_charges`, and it writes them
 * once. Every downstream number — the company invoice, the technician
 * payout, every margin report — reads those rows and never re-derives a
 * rate. Recomputing later against a live rate card would silently rewrite
 * invoices already sent, so the ledger is frozen at closure and corrected
 * only by new adjustment lines.
 *
 * Closure is also where the SLA clocks stop, which is what makes a bonus
 * or penalty real rather than projected. The engine that decides those
 * amounts is `src/Domain` and knows nothing about the database; this class
 * is the seam that feeds it rows and writes back what it returns.
 */
class TicketClosureService
{
    use LocatorAwareTrait;

    public function __construct(
        private readonly RateCardRepository $rates = new RateCardRepository(),
        private readonly EvidenceService $evidence = new EvidenceService(),
        private readonly TicketWorkflow $workflow = new TicketWorkflow(),
    ) {
    }

    /**
     * Price a ticket without committing anything.
     *
     * The same code path as closure, with persistence switched off, so a
     * quote shown to the desk and the invoice raised later cannot disagree.
     * That extends to an override: passing the same `override_*` inputs the
     * close call will carry shows what they will actually produce, which is
     * the only safe way to offer a manual figure at all.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function preview(int $ticketId, array $data = []): array
    {
        $override = $this->parseOverride($data);

        if (($override['ok'] ?? true) === false) {
            return $override;
        }

        return $this->computeAndMaybeFreeze(
            $ticketId,
            persist: false,
            override: $override['override'],
        );
    }

    /**
     * Close a ticket.
     *
     * @param array<string, mixed> $data
     * @return array{ok: true, totals: array<string, mixed>, lines: list<array<string, mixed>>}
     *        |array{ok: false, code: string, errors: array<string, list<string>>}
     */
    public function close(int $ticketId, array $data = [], ?int $actorUserId = null): array
    {
        // Parsed before anything is written, so a malformed manual figure is
        // rejected without having half-closed the ticket first.
        $parsed = $this->parseOverride($data, $actorUserId);
        if (($parsed['ok'] ?? true) === false) {
            return $parsed;
        }
        $override = $parsed['override'];

        $tickets = $this->fetchTable('Tickets');
        $ticket = $tickets->get($ticketId);

        if ($ticket->charges_frozen_at !== null) {
            return [
                'ok' => false,
                'code' => 'already_closed',
                'errors' => ['ticket' => ['This ticket is closed and its charges are frozen.']],
            ];
        }

        if (!$this->workflow->canTransition($ticket->status, 'closed')) {
            return [
                'ok' => false,
                'code' => 'invalid_transition',
                // Closing a job nobody visited is the transition worth
                // blocking: it produces a billable line with no evidence
                // behind it, which is exactly what a dispute attacks.
                'errors' => ['status' => [sprintf(
                    'A ticket that is "%s" cannot be closed yet.',
                    $ticket->status,
                )]],
            ];
        }

        // What each company demands before a job counts as done.
        $missing = $this->evidence->outstandingRequirements($ticketId);
        if ($missing !== []) {
            return [
                'ok' => false,
                'code' => 'evidence_required',
                'errors' => ['evidence' => $missing],
            ];
        }

        if (empty($data['resolution_id'])) {
            return [
                'ok' => false,
                'code' => 'validation_error',
                'errors' => ['resolution_id' => ['Record what was done to fix it.']],
            ];
        }

        $resolution = $this->fetchTable('Resolutions')->find()
            ->where([
                'id' => (int)$data['resolution_id'],
                'OR' => ['company_id IS' => null, 'company_id' => $ticket->company_id],
            ])
            ->first();

        if ($resolution === null) {
            return [
                'ok' => false,
                'code' => 'validation_error',
                'errors' => ['resolution_id' => ['Not a resolution this company recognises.']],
            ];
        }

        // Some outcomes must consume a part, and the desk should be told
        // before the technician leaves rather than at invoice time.
        if ($resolution->requires_spare) {
            $spareCount = $this->fetchTable('TicketSpares')->find()
                ->where(['ticket_id' => $ticketId])
                ->count();

            if ($spareCount === 0) {
                return [
                    'ok' => false,
                    'code' => 'validation_error',
                    'errors' => ['resolution_id' => [sprintf(
                        '"%s" requires at least one spare part to be recorded.',
                        $resolution->name,
                    )]],
                ];
            }
        }

        $connection = $tickets->getConnection();

        return $connection->transactional(
            function () use ($tickets, $ticket, $ticketId, $data, $actorUserId, $resolution, $override): array {
                $from = $ticket->status;
                $now = DateTime::now();

                $ticket->set('resolution_id', $resolution->id);
                $ticket->set('diagnosis', $data['diagnosis'] ?? $ticket->diagnosis);
                $ticket->set('closure_notes', $data['closure_notes'] ?? null);
                $ticket->set('closed_at', $now);
                $ticket->set('status', 'closed');

                if (isset($data['travel_km'])) {
                    $ticket->set('travel_km', (string)$data['travel_km']);
                }

                $tickets->saveOrFail($ticket);

                // A closure that earns nothing — "no fault found", customer
                // cancelled — still closes; it just produces no ledger.
                if (!$resolution->is_billable) {
                    $ticket->set('charges_computed_at', $now);
                    $ticket->set('charges_frozen_at', $now);
                    $tickets->saveOrFail($ticket);

                    $this->workflow->logEvent(
                        $ticketId,
                        'closed',
                        $from,
                        'closed',
                        $actorUserId,
                        sprintf('Closed as "%s". Not billable.', $resolution->name),
                        ['billable' => false],
                    );

                    $this->workflow->logEvent(
                        $ticketId,
                        'charges_frozen',
                        null,
                        null,
                        $actorUserId,
                        sprintf('Charges frozen — closed as "%s", not billable.', $resolution->name),
                        ['reason' => 'closed_not_billable', 'resolution' => $resolution->name],
                    );

                    return ['ok' => true, 'totals' => [], 'lines' => []];
                }

                $result = $this->computeAndMaybeFreeze(
                    $ticketId,
                    persist: true,
                    actorUserId: $actorUserId,
                    override: $override,
                );

                if (($result['ok'] ?? true) === false) {
                    // Unpriceable work is a real gap in the agreement, not a
                    // reason to lose the closure. The ticket stays closed and
                    // the charges stay unfrozen so the desk can get a rate
                    // confirmed and re-run pricing.
                    $this->workflow->logEvent(
                        $ticketId,
                        'closed',
                        $from,
                        'closed',
                        $actorUserId,
                        'Closed, but the job could not be priced.',
                        $result,
                    );

                    return $result;
                }

                $this->workflow->logEvent(
                    $ticketId,
                    'closed',
                    $from,
                    'closed',
                    $actorUserId,
                    $override === null
                        ? sprintf('Closed as "%s".', $resolution->name)
                        : sprintf(
                            'Closed as "%s". Service charge set manually to %s.',
                            $resolution->name,
                            $override->amount->format(),
                        ),
                    [
                        'totals' => $result['totals'],
                        'base_source' => $result['base_source'],
                        'override' => $override?->toSnapshot(),
                    ],
                );

                return $result;
            },
        );
    }

    /**
     * Run the engine over a ticket, optionally writing the result.
     *
     * @return array<string, mixed>
     */
    private function computeAndMaybeFreeze(
        int $ticketId,
        bool $persist,
        ?int $actorUserId = null,
        ?BaseOverride $override = null,
    ): array {
        $ticket = $this->fetchTable('Tickets')->get($ticketId, contain: ['JobTypes', 'ProductCategories']);

        $companyId = (int)$ticket->company_id;

        // Priced under the terms in force when the job arrived, not
        // today's. A ticket received in March is a March job even if it
        // closes in April and the card changed in between.
        $onDate = (new DateTime($ticket->received_at))->format('Y-m-d');

        try {
            $terms = $this->rates->agreementTerms($companyId, $onDate);
            // The card bound at intake wins, so a card published mid-job
            // cannot reprice work already quoted to the customer.
            $cardId = $ticket->rate_card_id !== null
                ? (int)$ticket->rate_card_id
                : $this->rates->activeCardId($companyId, $onDate);
        } catch (RecordNotFoundException $e) {
            return [
                'ok' => false,
                'code' => 'no_agreement',
                'errors' => ['company' => [$e->getMessage()]],
            ];
        }

        $jobTypeCode = (string)($ticket->job_type?->code ?? '');
        $scope = WarrantyScope::tryFrom((string)$ticket->warranty_scope) ?? WarrantyScope::NotApplicable;

        $timing = (new SlaCalculator())->calculate(
            receivedAt: $this->toImmutable($ticket->received_at) ?? new DateTimeImmutable(),
            firstContactAt: $this->toImmutable($ticket->first_contact_at),
            visitedAt: $this->toImmutable($ticket->visited_at),
            closedAt: $this->toImmutable($ticket->closed_at),
            holds: $this->holdsFor($ticketId),
            windows: $terms->windows(),
        );

        $rate = null;

        try {
            $rate = (new RateResolver())->resolve(
                new RateContext(
                    jobTypeCode: $jobTypeCode,
                    warrantyScope: $scope,
                    productCategoryCode: $ticket->product_category?->code,
                    sizeInch: $ticket->size_inch !== null ? (float)$ticket->size_inch : null,
                ),
                $this->rates->items($cardId),
                $cardId,
            );
        } catch (RateNotFoundException $e) {
            if ($override === null) {
                $config = new CompanyConfigRepository();
                $defaultServiceCharge = $config->settings($companyId)->int(SettingCatalog::CLOSURE_DEFAULT_SERVICE_CHARGE, 400);

                // A BOQ line already priced this job by hand. The fallback
                // exists to stop an unpriceable ticket closing at zero, and
                // that is no longer the situation — adding it now would bill
                // a service charge twice, once as the BOQ line the desk
                // agreed and once as the default of the same name.
                //
                // The base still has to exist: ChargeBuilder refuses a job
                // with neither a card item nor an override, and a zero line
                // records *why* the total came from the BOQ instead of
                // leaving a ticket that looks unpriced.
                if ($this->hasBoqLine($ticketId)) {
                    $override = new BaseOverride(
                        amount: Money::zero(),
                        payer: Payer::Company,
                        reason: 'Priced by BOQ lines; no rate card item applies.',
                        authorisedByUserId: $actorUserId,
                    );
                } elseif ($defaultServiceCharge > 0) {
                    $override = new BaseOverride(
                        amount: Money::fromRupees($defaultServiceCharge),
                        payer: Payer::Company,
                        reason: sprintf('Basic Service Charge (₹%d)', $defaultServiceCharge),
                        authorisedByUserId: $actorUserId,
                    );
                } else {
                    return [
                        'ok' => false,
                        'code' => 'rate_not_found',
                        'errors' => ['rate' => [$e->getMessage()]],
                        'context' => $e->context->toArray(),
                        'override_accepted' => true,
                    ];
                }
            }

            // With no item to inherit from, the override has to say who pays
            // — scope alone does not decide it for every job type.
            if ($override->payer === null) {
                return [
                    'ok' => false,
                    'code' => 'validation_error',
                    'errors' => ['override_payer' => [
                        'This job has no rate card line, so state whether the '
                        . 'company or the customer pays the manual amount.',
                    ]],
                ];
            }
        } catch (UnrateableTicketException $e) {
            // Deliberately NOT overridable. An unknown warranty scope does
            // not just leave the amount open, it leaves who owes it open,
            // and it also drives how spares on the same ticket are billed.
            // Overriding the base charge would paper over all of that.
            return [
                'ok' => false,
                'code' => 'unrateable_ticket',
                'errors' => ['rate' => [$e->getMessage()]],
            ];
        }

        $matched = (new SlaEvaluator())->evaluate(
            $timing,
            $this->rates->slaRules($cardId),
            $jobTypeCode,
            $scope,
        );

        $spares = $this->sparesFor($ticketId);
        $travelKm = $ticket->travel_km !== null ? (float)$ticket->travel_km : null;

        $charges = (new ChargeBuilder())->build(
            $rate,
            $matched,
            $terms,
            $timing,
            $travelKm,
            $spares,
            $override,
        );

        // The technician side, from the rate in force for them on the day,
        // bound to this company's payout policy.
        if ($ticket->assigned_technician_id !== null) {
            $technicianRate = $this->rates->technicianRate(
                (int)$ticket->assigned_technician_id,
                $terms,
                $onDate,
                $jobTypeCode,
            );

            if ($technicianRate !== null) {
                $charges = $charges->withLines(
                    (new PayoutBuilder())->build($charges, $technicianRate, $jobTypeCode, $travelKm),
                );
            }
        }

        if ($persist) {
            $this->freeze($ticketId, $cardId, $terms->id, $charges, $actorUserId);
        }

        return [
            'ok' => true,
            'rate_card_id' => $cardId,
            // What the card would have charged, next to what was actually
            // charged. Equal on an ordinary ticket; the point of the pair is
            // the ticket where they are not.
            'base_source' => $override !== null ? 'manual' : 'rate_card',
            'card_amount' => $rate?->amount()->jsonSerialize(),
            'override_reason' => $override?->reason,
            'sla' => $timing->toArray(),
            'matched_rules' => array_map(
                static fn ($m): array => [
                    'code' => $m->rule->code,
                    'description' => $m->description(),
                    'amount' => $m->amount()->jsonSerialize(),
                ],
                $matched,
            ),
            'lines' => array_map(
                static fn (ChargeLine $l): array => [
                    'type' => $l->type->value,
                    'ledger' => $l->ledger->value,
                    'description' => $l->description,
                    'quantity' => $l->quantity,
                    'amount' => $l->amount->jsonSerialize(),
                ],
                $charges->lines,
            ),
            'totals' => $charges->summary(),
        ];
    }

    /**
     * Read a manual base charge out of the request, if one was offered.
     *
     * Returns the same failure envelope as everything else here so a bad
     * figure is reported per-field rather than thrown.
     *
     * `override_base_amount` is a rupee string ("650", "650.50") rather than
     * paise, because that is what the person typing it into the close dialog
     * is looking at. A negative amount is refused: reducing what a job
     * earned after the fact is an adjustment, and adjustments carry their own
     * authorisation trail.
     *
     * @param array<string, mixed> $data
     * @return array{ok: true, override: ?\App\Domain\Charge\BaseOverride}|array{ok: false, code: string, errors: array<string, list<string>>}
     */
    private function parseOverride(array $data, ?int $actorUserId = null): array
    {
        $raw = $data['override_base_amount'] ?? null;

        if ($raw === null || $raw === '') {
            return ['ok' => true, 'override' => null];
        }

        $reason = trim((string)($data['override_reason'] ?? ''));
        if ($reason === '') {
            return [
                'ok' => false,
                'code' => 'validation_error',
                'errors' => ['override_reason' => [
                    'Say why the rate card amount is not being used. It prints '
                    . 'on the invoice line and is what answers the query later.',
                ]],
            ];
        }

        try {
            $amount = Money::parse((string)$raw);
        } catch (InvalidArgumentException $e) {
            return [
                'ok' => false,
                'code' => 'validation_error',
                'errors' => ['override_base_amount' => [$e->getMessage()]],
            ];
        }

        if ($amount->isNegative()) {
            return [
                'ok' => false,
                'code' => 'validation_error',
                'errors' => ['override_base_amount' => [
                    'A service charge cannot be negative. To reduce what a '
                    . 'closed job earned, raise an adjustment instead.',
                ]],
            ];
        }

        $payer = null;
        $payerValue = trim((string)($data['override_payer'] ?? ''));
        if ($payerValue !== '') {
            $payer = Payer::tryFrom($payerValue);
            if ($payer === null) {
                return [
                    'ok' => false,
                    'code' => 'validation_error',
                    'errors' => ['override_payer' => ['Use either "company" or "customer".']],
                ];
            }
        }

        return [
            'ok' => true,
            'override' => new BaseOverride(
                amount: $amount,
                reason: mb_substr($reason, 0, 200),
                payer: $payer,
                authorisedByUserId: $actorUserId,
            ),
        ];
    }

    /**
     * Write the ledger and lock it.
     */
    /**
     * Whether the desk agreed any additional service on this job by hand.
     */
    private function hasBoqLine(int $ticketId): bool
    {
        return $this->fetchTable('TicketCharges')->find()
            ->where(['ticket_id' => $ticketId, 'line_type' => ChargeLineType::Boq->value])
            ->count() > 0;
    }

    private function freeze(
        int $ticketId,
        int $rateCardId,
        int $agreementId,
        ChargeSet $charges,
        ?int $actorUserId,
    ): void {
        $chargesTable = $this->fetchTable('TicketCharges');
        $now = DateTime::now();

        // Only ever reached for a ticket with no frozen charges, so this
        // clears a failed earlier attempt rather than a settled ledger.
        //
        // BOQ lines are exempt. They are not a stale computation to be
        // redone — they are work the desk agreed with the customer while
        // the job was open, which the rate card never knew about. Deleting
        // them here would silently drop billable work between the desk
        // agreeing it and the invoice being raised.
        $chargesTable->deleteAll([
            'ticket_id' => $ticketId,
            'is_frozen' => false,
            'line_type !=' => ChargeLineType::Boq->value,
        ]);

        foreach ($charges->lines as $line) {
            $row = $line->toRow() + [
                'ticket_id' => $ticketId,
                'rate_card_id' => $rateCardId,
                'company_agreement_id' => $agreementId,
                'computed_at' => $now,
                'computed_by_user_id' => $actorUserId,
                'is_frozen' => true,
                'settlement_status' => 'open',
            ];

            $chargesTable->saveOrFail($chargesTable->newEntity($row));
        }

        // The BOQ lines that survived above join the ledger on the same
        // terms as the computed ones: locked, and stamped with the card and
        // agreement the rest of the ticket was priced under, so an invoice
        // query that filters on either does not miss them.
        $chargesTable->updateAll(
            [
                'is_frozen' => true,
                'rate_card_id' => $rateCardId,
                'company_agreement_id' => $agreementId,
            ],
            [
                'ticket_id' => $ticketId,
                'line_type' => ChargeLineType::Boq->value,
                'is_frozen' => false,
            ],
        );

        $this->fetchTable('Tickets')->updateAll(
            [
                'charges_computed_at' => $now,
                'charges_frozen_at' => $now,
                'rate_card_id' => $rateCardId,
                'company_agreement_id' => $agreementId,
            ],
            ['id' => $ticketId],
        );

        $this->workflow->logEvent(
            $ticketId,
            'charges_frozen',
            null,
            null,
            $actorUserId,
            sprintf('Charges frozen on closure — %d line(s).', count($charges->lines)),
            ['reason' => 'closed', 'rate_card_id' => $rateCardId, 'agreement_id' => $agreementId],
        );
    }

    /**
     * @return list<HoldPeriod>
     */
    private function holdsFor(int $ticketId): array
    {
        $rows = $this->fetchTable('TicketHolds')->find()
            ->select([
                'TicketHolds.started_at',
                'TicketHolds.ended_at',
                'TicketHolds.reason_code',
                'pauses_sla' => 'HoldReasons.pauses_sla',
            ])
            ->join(['HoldReasons' => [
                'table' => 'hold_reasons',
                'type' => 'LEFT',
                'conditions' => 'HoldReasons.id = TicketHolds.hold_reason_id',
            ]])
            ->where(['TicketHolds.ticket_id' => $ticketId])
            ->disableHydration()
            ->all();

        $holds = [];
        foreach ($rows as $row) {
            // A hold whose reason does not stop the clock is still recorded
            // history, but it must not reach the calculator — passing it
            // would excuse a delay that was ours.
            if ($row['pauses_sla'] !== null && !$row['pauses_sla']) {
                continue;
            }

            // Through toImmutable(), never a string cast. The ORM hands back
            // Cake\I18n\DateTime here too, and casting one renders it in the
            // application's locale — "27/07/26, 9:39 am" — which
            // DateTimeImmutable cannot parse. See toImmutable() below; this
            // call site was the one that missed it, so every ticket that had
            // ever been on hold threw at preview and at closure.
            $startedAt = $this->toImmutable($row['started_at']);
            if ($startedAt === null) {
                continue;
            }

            $holds[] = new HoldPeriod(
                startedAt: $startedAt,
                endedAt: $this->toImmutable($row['ended_at']),
                reasonCode: $row['reason_code'] !== null ? (string)$row['reason_code'] : null,
            );
        }

        return $holds;
    }

    /**
     * @return list<SpareUsage>
     */
    private function sparesFor(int $ticketId): array
    {
        $rows = $this->fetchTable('TicketSpares')->find()
            ->select([
                'TicketSpares.id',
                'TicketSpares.quantity',
                'TicketSpares.unit_cost_paise',
                'TicketSpares.margin_pct',
                'TicketSpares.charged_to',
                'part_no' => 'SpareParts.part_no',
                'part_name' => 'SpareParts.name',
            ])
            ->join(['SpareParts' => [
                'table' => 'spare_parts',
                'type' => 'INNER',
                'conditions' => 'SpareParts.id = TicketSpares.spare_part_id',
            ]])
            ->where(['TicketSpares.ticket_id' => $ticketId])
            ->disableHydration()
            ->all();

        $spares = [];
        foreach ($rows as $row) {
            $spares[] = new SpareUsage(
                id: (int)$row['id'],
                partNo: (string)$row['part_no'],
                name: (string)$row['part_name'],
                quantity: (int)$row['quantity'],
                unitCost: Money::fromPaise((int)$row['unit_cost_paise']),
                marginPct: (string)$row['margin_pct'],
                chargedTo: Payer::from((string)$row['charged_to']),
            );
        }

        return $spares;
    }

    /**
     * Convert whatever the ORM handed back into a plain immutable date.
     *
     * Never via a string cast. `Cake\I18n\DateTime::__toString()` renders
     * in the application's locale — "27/07/26, 6:20 am" — which
     * DateTimeImmutable cannot parse, so a cast fails at closure time on
     * every ticket rather than in a test. Formatting explicitly, or taking
     * the timestamp, is locale-independent.
     */
    private function toImmutable(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        return new DateTimeImmutable((string)$value);
    }
}
