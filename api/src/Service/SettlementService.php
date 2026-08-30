<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\Enum\ChargeLineType;
use App\Domain\Enum\Ledger;
use App\Domain\Enum\PayoutMethod;
use App\Domain\Money;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use InvalidArgumentException;

/**
 * Turning frozen charge lines into an invoice to a company and a payout to
 * a technician.
 *
 * Both runs work the same way and it is the important property: they
 * SELECT charge lines that are already frozen and copy them. Nothing here
 * prices anything. A run that recalculated would produce an invoice that
 * disagrees with the ticket it came from the moment a rate card changes,
 * and reconciling those two numbers afterwards is not possible — nobody
 * knows which was right.
 *
 * A charge line is claimed exactly once. `settlement_status` moves from
 * open to invoiced when it lands on an invoice, which is what stops the
 * same job being billed in March and again in April by an operator who
 * ran the cycle twice.
 */
class SettlementService
{
    use LocatorAwareTrait;

    public function __construct(
        private readonly RateCardRepository $rates = new RateCardRepository(),
    ) {
    }

    // -----------------------------------------------------------------
    // company invoices
    // -----------------------------------------------------------------

    /**
     * What would go on an invoice for this period, without creating one.
     *
     * @return array<string, mixed>
     */
    public function previewInvoice(int $companyId, string $periodStart, string $periodEnd): array
    {
        $lines = $this->claimableCompanyLines($companyId, $periodStart, $periodEnd);

        return [
            'company_id' => $companyId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'totals' => $this->summariseInvoiceLines($lines),
            'ticket_count' => count(array_unique(array_column($lines, 'ticket_id'))),
            'line_count' => count($lines),
        ];
    }

    /**
     * Raise a draft invoice for everything unbilled in a period.
     *
     * @return array{ok: true, invoice_id: int, invoice_no: string, totals: array<string, mixed>}
     *        |array{ok: false, errors: array<string, list<string>>}
     */
    public function generateInvoice(
        int $companyId,
        string $periodStart,
        string $periodEnd,
        ?int $actorUserId = null,
    ): array {
        $invoices = $this->fetchTable('CompanyInvoices');
        $connection = $invoices->getConnection();

        return $connection->transactional(
            function () use ($invoices, $companyId, $periodStart, $periodEnd, $actorUserId): array {
                $lines = $this->claimableCompanyLines($companyId, $periodStart, $periodEnd);

                if ($lines === []) {
                    return ['ok' => false, 'errors' => ['period' => [
                        'Nothing is waiting to be invoiced for this company in that period.',
                    ]]];
                }

                $totals = $this->summariseInvoiceLines($lines);

                $agreementId = null;
                $cycleDay = 10;
                try {
                    $terms = $this->rates->agreementTerms($companyId, $periodEnd);
                    $agreementId = $terms->id;
                } catch (\Cake\Datasource\Exception\RecordNotFoundException) {
                    // Invoicing a period whose agreement has since lapsed is
                    // legitimate — the work was done under it.
                }

                $agreementRow = $this->fetchTable('CompanyAgreements')->find()
                    ->select(['invoice_cycle_day'])
                    ->where(['company_id' => $companyId])
                    ->orderByDesc('effective_from')
                    ->disableHydration()
                    ->first();

                if ($agreementRow !== null) {
                    // Clause 5 for Dianora is the 10th; each company sets
                    // its own, so the due date is read rather than assumed.
                    $cycleDay = (int)$agreementRow['invoice_cycle_day'];
                }

                $invoiceNo = $this->nextInvoiceNo($companyId, $periodEnd);

                $invoice = $invoices->newEntity([
                    'company_id' => $companyId,
                    'company_agreement_id' => $agreementId,
                    'invoice_no' => $invoiceNo,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'cycle_date' => $this->cycleDateAfter($periodEnd, $cycleDay),
                    'due_at' => $this->cycleDateAfter($periodEnd, $cycleDay),
                    'status' => 'draft',
                    'subtotal_paise' => $totals['subtotal_paise'],
                    'sla_bonus_paise' => $totals['sla_bonus_paise'],
                    'sla_penalty_paise' => $totals['sla_penalty_paise'],
                    'travel_paise' => $totals['travel_paise'],
                    'spare_paise' => $totals['spare_paise'],
                    'royalty_paise' => $totals['royalty_paise'],
                    'total_paise' => $totals['total_paise'],
                    'ticket_count' => count(array_unique(array_column($lines, 'ticket_id'))),
                    'created_by_user_id' => $actorUserId,
                ]);

                $invoices->saveOrFail($invoice);

                $invoiceLines = $this->fetchTable('CompanyInvoiceLines');
                $chargeIds = [];
                $sort = 0;

                foreach ($lines as $line) {
                    $invoiceLines->saveOrFail($invoiceLines->newEntity([
                        'company_invoice_id' => $invoice->id,
                        'ticket_charge_id' => (int)$line['id'],
                        'ticket_id' => (int)$line['ticket_id'],
                        // Copied, not joined. The header totals are re-derived
                        // from these rows whenever a line is restated, and a
                        // join back to the frozen charge would make a raised
                        // invoice re-total itself the next time the ticket is
                        // adjusted.
                        'line_type' => (string)$line['line_type'],
                        'ledger' => (string)$line['ledger'],
                        'description' => sprintf('%s — %s', $line['ticket_no'], $line['description']),
                        'quantity' => $line['quantity'],
                        'unit_amount_paise' => $line['unit_amount_paise'],
                        'amount_paise' => $line['amount_paise'],
                        'sort_order' => $sort++,
                    ]));

                    $chargeIds[] = (int)$line['id'];
                }

                // Claim the lines. Done inside the transaction so a failure
                // anywhere above leaves them available rather than marked
                // as billed on an invoice that does not exist.
                $this->fetchTable('TicketCharges')->updateAll(
                    ['settlement_status' => 'invoiced'],
                    ['id IN' => $chargeIds],
                );

                return [
                    'ok' => true,
                    'invoice_id' => (int)$invoice->id,
                    'invoice_no' => $invoiceNo,
                    'totals' => $totals,
                ];
            },
        );
    }

    /**
     * Frozen, unbilled, company-side lines for a period.
     *
     * Selected on `closed_at` rather than on when the charge row was
     * written: the period being billed is the period the work was
     * completed in, and a line recomputed after a correction must not
     * migrate into a later invoice.
     *
     * @return list<array<string, mixed>>
     */
    private function claimableCompanyLines(int $companyId, string $periodStart, string $periodEnd): array
    {
        return $this->fetchTable('TicketCharges')->find()
            ->select([
                'TicketCharges.id',
                'TicketCharges.ticket_id',
                'TicketCharges.line_type',
                'TicketCharges.ledger',
                'TicketCharges.description',
                'TicketCharges.quantity',
                'TicketCharges.unit_amount_paise',
                'TicketCharges.amount_paise',
                'ticket_no' => 'Tickets.ticket_no',
            ])
            ->join(['Tickets' => [
                'table' => 'tickets',
                'type' => 'INNER',
                'conditions' => 'Tickets.id = TicketCharges.ticket_id',
            ]])
            ->where([
                'Tickets.company_id' => $companyId,
                'Tickets.closed_at >=' => $periodStart . ' 00:00:00',
                'Tickets.closed_at <=' => $periodEnd . ' 23:59:59',
                'TicketCharges.is_frozen' => true,
                'TicketCharges.settlement_status' => 'open',
                // Both directions of the company relationship belong on one
                // document: what they owe us, and the royalty we owe back.
                // Netting them is what makes the total the amount actually
                // due, which is what clause 5 settles on.
                'TicketCharges.ledger IN' => ['company_receivable', 'company_payable'],
            ])
            ->orderByAsc('Tickets.closed_at')
            ->orderByAsc('TicketCharges.id')
            ->disableHydration()
            ->all()
            ->toList();
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array<string, int>
     */
    private function summariseInvoiceLines(array $lines): array
    {
        $totals = [
            'subtotal_paise' => 0,
            'sla_bonus_paise' => 0,
            'sla_penalty_paise' => 0,
            'travel_paise' => 0,
            'spare_paise' => 0,
            'royalty_paise' => 0,
            'total_paise' => 0,
        ];

        foreach ($lines as $line) {
            $amount = (int)$line['amount_paise'];
            $type = (string)$line['line_type'];

            match ($type) {
                'base' => $totals['subtotal_paise'] += $amount,
                'sla_bonus' => $totals['sla_bonus_paise'] += $amount,
                // Already negative on the receivable ledger, so it sums
                // into the total without a sign flip here.
                'sla_penalty' => $totals['sla_penalty_paise'] += $amount,
                'travel' => $totals['travel_paise'] += $amount,
                'spare_cost', 'spare_margin' => $totals['spare_paise'] += $amount,
                'company_royalty' => $totals['royalty_paise'] += $amount,
                default => $totals['subtotal_paise'] += $amount,
            };
        }

        // The royalty sits on the payable ledger as a positive magnitude —
        // money flowing the other way — so it is subtracted here rather
        // than added. Getting this sign wrong overstates every invoice by
        // twice the royalty.
        $totals['total_paise'] = $totals['subtotal_paise']
            + $totals['sla_bonus_paise']
            + $totals['sla_penalty_paise']
            + $totals['travel_paise']
            + $totals['spare_paise']
            - $totals['royalty_paise'];

        return $totals;
    }

    /**
     * One invoice, broken down into the parts that make up its total.
     *
     * A total on its own is what an argument with a company starts from.
     * This returns the same number three ways — by bucket, by ticket, and
     * line by line — so the desk can point at the Rs.75 rather than at the
     * Rs.496.
     *
     * @return array<string, mixed>|null Null when there is no such invoice.
     */
    public function invoiceDetail(int $invoiceId): ?array
    {
        $invoice = $this->fetchTable('CompanyInvoices')->find()
            ->contain(['Companies'])
            ->where(['CompanyInvoices.id' => $invoiceId])
            ->first();

        if ($invoice === null) {
            return null;
        }

        $rows = $this->fetchTable('CompanyInvoiceLines')->find()
            ->select([
                'CompanyInvoiceLines.id',
                'CompanyInvoiceLines.ticket_id',
                'CompanyInvoiceLines.ticket_charge_id',
                'CompanyInvoiceLines.line_type',
                'CompanyInvoiceLines.ledger',
                'CompanyInvoiceLines.description',
                'CompanyInvoiceLines.quantity',
                'CompanyInvoiceLines.unit_amount_paise',
                'CompanyInvoiceLines.amount_paise',
                'CompanyInvoiceLines.original_amount_paise',
                'CompanyInvoiceLines.override_reason',
                'CompanyInvoiceLines.overridden_at',
                'ticket_no' => 'Tickets.ticket_no',
                'overridden_by' => 'OverriddenByUsers.name',
            ])
            ->leftJoinWith('Tickets')
            ->leftJoinWith('OverriddenByUsers')
            ->where(['CompanyInvoiceLines.company_invoice_id' => $invoiceId])
            ->orderByAsc('CompanyInvoiceLines.sort_order')
            ->orderByAsc('CompanyInvoiceLines.id')
            ->disableHydration()
            ->all()
            ->toList();

        $totals = $this->summariseInvoiceLines($rows);
        $paid = (int)$invoice->paid_paise;

        // Only a draft is still ours to restate. Once the invoice has been
        // emailed the company is holding a document with these numbers on
        // it, and changing one silently is exactly what the frozen ledger
        // exists to prevent — after that point a correction is an
        // adjustment on the ticket, which lands on the next run.
        $isEditable = $invoice->status === 'draft';

        return [
            'id' => (int)$invoice->id,
            'invoice_no' => $invoice->invoice_no,
            'status' => $invoice->status,
            'company' => $invoice->company === null ? null : [
                'id' => (int)$invoice->company->id,
                'code' => $invoice->company->code,
                'name' => $invoice->company->name,
            ],
            'period_start' => $invoice->period_start->format('Y-m-d'),
            'period_end' => $invoice->period_end->format('Y-m-d'),
            'cycle_date' => $invoice->cycle_date?->format('Y-m-d'),
            'due_at' => $invoice->due_at?->format('Y-m-d'),
            'ticket_count' => (int)$invoice->ticket_count,
            'is_editable' => $isEditable,
            'locked_reason' => $isEditable ? null : sprintf(
                'This invoice is %s. Correct it with an adjustment on the ticket — '
                . 'that lands on the next run and leaves the sent document intact.',
                $invoice->status,
            ),
            'split' => $this->labelledInvoiceSplit($totals),
            'totals' => [
                'total' => Money::fromPaise($totals['total_paise'])->jsonSerialize(),
                'paid' => Money::fromPaise($paid)->jsonSerialize(),
                'balance' => Money::fromPaise($totals['total_paise'] - $paid)->jsonSerialize(),
                'line_count' => count($rows),
            ],
            'tickets' => $this->groupLinesByTicket($rows),
        ];
    }

    /**
     * Restate one line on a draft invoice.
     *
     * The frozen `ticket_charges` row is never touched. What changes is the
     * invoice's own copy, with the amount it was raised at kept beside it,
     * so the document stays reconcilable to the ledger as "this line, and
     * what we agreed to bill instead".
     *
     * @param string $amount Signed rupee string, e.g. "450" or "-75.50".
     * @return array{ok: true, invoice: array<string, mixed>}
     *        |array{ok: false, code: string, errors: array<string, list<string>>}
     */
    public function overrideInvoiceLine(
        int $invoiceId,
        int $lineId,
        string $amount,
        string $reason,
        ?int $actorUserId = null,
    ): array {
        $lines = $this->fetchTable('CompanyInvoiceLines');

        $guard = $this->guardInvoiceLine($invoiceId, $lineId);
        if ($guard !== null) {
            return $guard;
        }

        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'code' => 'validation_error', 'errors' => ['reason' => [
                'An override needs a reason. Without one it is indistinguishable '
                . 'from a mistake when the company queries the invoice.',
            ]]];
        }

        try {
            $money = Money::parse(trim($amount));
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'code' => 'validation_error', 'errors' => ['amount' => [$e->getMessage()]]];
        }

        $line = $lines->get($lineId);

        // Kept from the first override rather than the last, so the column
        // always answers "what did the ledger say?" — restating a line
        // twice must not make the second override look like the original.
        if ($line->original_amount_paise === null) {
            $line->set('original_amount_paise', (int)$line->amount_paise);
        }

        $line->set('amount_paise', $money->paise);
        $line->set('override_reason', $reason);
        $line->set('overridden_at', DateTime::now());
        $line->set('overridden_by_user_id', $actorUserId);

        if (!$lines->save($line)) {
            return ['ok' => false, 'code' => 'validation_error', 'errors' => $line->getErrors()];
        }

        $this->recalculateInvoice($invoiceId);

        return ['ok' => true, 'invoice' => (array)$this->invoiceDetail($invoiceId)];
    }

    /**
     * Put a restated line back to the amount the run raised it at.
     *
     * @return array{ok: true, invoice: array<string, mixed>}
     *        |array{ok: false, code: string, errors: array<string, list<string>>}
     */
    public function resetInvoiceLine(int $invoiceId, int $lineId): array
    {
        $guard = $this->guardInvoiceLine($invoiceId, $lineId);
        if ($guard !== null) {
            return $guard;
        }

        $lines = $this->fetchTable('CompanyInvoiceLines');
        $line = $lines->get($lineId);

        if ($line->original_amount_paise !== null) {
            $line->set('amount_paise', (int)$line->original_amount_paise);
            $line->set('original_amount_paise', null);
            $line->set('override_reason', null);
            $line->set('overridden_at', null);
            $line->set('overridden_by_user_id', null);

            $lines->saveOrFail($line);
            $this->recalculateInvoice($invoiceId);
        }

        return ['ok' => true, 'invoice' => (array)$this->invoiceDetail($invoiceId)];
    }

    /**
     * Shared checks for both ways of changing a line.
     *
     * @return array{ok: false, code: string, errors: array<string, list<string>>}|null
     */
    private function guardInvoiceLine(int $invoiceId, int $lineId): ?array
    {
        $line = $this->fetchTable('CompanyInvoiceLines')->find()
            ->where(['id' => $lineId, 'company_invoice_id' => $invoiceId])
            ->first();

        if ($line === null) {
            // Matched on both ids on purpose: a line id from another
            // invoice must not be editable through this invoice's URL.
            return ['ok' => false, 'code' => 'not_found', 'errors' => ['line_id' => [
                'That line is not on this invoice.',
            ]]];
        }

        $invoice = $this->fetchTable('CompanyInvoices')->get($invoiceId);

        if ($invoice->status !== 'draft') {
            return ['ok' => false, 'code' => 'invalid_state', 'errors' => ['invoice' => [sprintf(
                'This invoice is %s and can no longer be edited. Raise an '
                . 'adjustment on the ticket instead — it lands on the next run.',
                $invoice->status,
            )]]];
        }

        return null;
    }

    /**
     * Re-derive the header from the lines as they now stand.
     *
     * The header is a cache of the lines, not an independent number, so it
     * is always recomputed in full rather than nudged by the delta — a
     * delta would drift the moment any line changed by another path.
     */
    private function recalculateInvoice(int $invoiceId): void
    {
        $rows = $this->fetchTable('CompanyInvoiceLines')->find()
            ->select(['line_type', 'amount_paise', 'ticket_id'])
            ->where(['company_invoice_id' => $invoiceId])
            ->disableHydration()
            ->all()
            ->toList();

        $totals = $this->summariseInvoiceLines($rows);

        $invoices = $this->fetchTable('CompanyInvoices');
        $invoice = $invoices->get($invoiceId);

        foreach ($totals as $column => $value) {
            $invoice->set($column, $value);
        }

        $invoice->set('ticket_count', count(array_filter(
            array_unique(array_column($rows, 'ticket_id')),
            fn ($id): bool => $id !== null,
        )));

        $invoices->saveOrFail($invoice);
    }

    /**
     * The header totals as a display-ready list.
     *
     * The royalty carries an explicit sign because it is the one bucket
     * that reduces the total, and a reader who assumes every row adds up
     * gets a number that does not reconcile.
     *
     * @param array<string, int> $totals
     * @return list<array<string, mixed>>
     */
    private function labelledInvoiceSplit(array $totals): array
    {
        $buckets = [
            ['key' => 'subtotal', 'label' => 'Service charges', 'paise' => $totals['subtotal_paise'], 'sign' => 1],
            ['key' => 'sla_bonus', 'label' => 'SLA incentives', 'paise' => $totals['sla_bonus_paise'], 'sign' => 1],
            ['key' => 'sla_penalty', 'label' => 'SLA deductions', 'paise' => $totals['sla_penalty_paise'], 'sign' => 1],
            ['key' => 'travel', 'label' => 'Travel', 'paise' => $totals['travel_paise'], 'sign' => 1],
            ['key' => 'spare', 'label' => 'Spare parts', 'paise' => $totals['spare_paise'], 'sign' => 1],
            ['key' => 'royalty', 'label' => 'Company royalty', 'paise' => $totals['royalty_paise'], 'sign' => -1],
        ];

        return array_map(
            fn (array $bucket): array => [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'sign' => $bucket['sign'],
                'amount' => Money::fromPaise($bucket['paise'])->jsonSerialize(),
                // What this bucket contributes to the total, sign applied.
                'effect' => Money::fromPaise($bucket['sign'] * $bucket['paise'])->jsonSerialize(),
            ],
            $buckets,
        );
    }

    /**
     * Invoice lines grouped under the ticket that produced them.
     *
     * Grouped rather than flat because a query from a company is almost
     * always about one job, and "what did we charge for DIN-202607-000001"
     * should not require reading six rows scattered through a list.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function groupLinesByTicket(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            // Lines with no ticket behind them — a manual fee, say — are
            // legitimate, so they get their own group rather than being
            // dropped by an array key of null.
            $key = $row['ticket_id'] === null ? 'unattributed' : (string)$row['ticket_id'];

            $groups[$key] ??= [
                'ticket_id' => $row['ticket_id'] === null ? null : (int)$row['ticket_id'],
                'ticket_no' => $row['ticket_no'] ?? 'Not attributed to a ticket',
                'subtotal' => 0,
                'lines' => [],
            ];

            $groups[$key]['subtotal'] += (int)$row['amount_paise'];
            $groups[$key]['lines'][] = $this->presentSettlementLine($row);
        }

        return array_values(array_map(
            fn (array $group): array => [
                'ticket_id' => $group['ticket_id'],
                'ticket_no' => $group['ticket_no'],
                'subtotal' => Money::fromPaise($group['subtotal'])->jsonSerialize(),
                'lines' => $group['lines'],
            ],
            $groups,
        ));
    }

    /**
     * One settlement line, ready to render.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentSettlementLine(array $row): array
    {
        $type = ChargeLineType::tryFrom((string)$row['line_type']);
        $ledger = isset($row['ledger']) && $row['ledger'] !== null
            ? Ledger::tryFrom((string)$row['ledger'])
            : null;

        $original = $row['original_amount_paise'] ?? null;

        return [
            'id' => (int)$row['id'],
            'ticket_id' => $row['ticket_id'] === null ? null : (int)$row['ticket_id'],
            'ticket_no' => $row['ticket_no'] ?? null,
            'line_type' => $row['line_type'],
            'type_label' => $type?->label() ?? (string)$row['line_type'],
            'ledger' => $ledger?->value,
            'ledger_label' => $ledger?->label(),
            'description' => $row['description'],
            'quantity' => $row['quantity'] ?? null,
            'unit_amount' => isset($row['unit_amount_paise']) && $row['unit_amount_paise'] !== null
                ? Money::fromPaise((int)$row['unit_amount_paise'])->jsonSerialize()
                : null,
            'amount' => Money::fromPaise((int)$row['amount_paise'])->jsonSerialize(),
            'is_overridden' => $original !== null,
            'original_amount' => $original === null
                ? null
                : Money::fromPaise((int)$original)->jsonSerialize(),
            'override_reason' => $row['override_reason'] ?? null,
            // ISO rather than the default string cast, which renders in the
            // request's locale and would reach the client as "27/07/26".
            'overridden_at' => $row['overridden_at'] instanceof DateTime
                ? $row['overridden_at']->format(DATE_ATOM)
                : null,
            'overridden_by' => $row['overridden_by'] ?? null,
        ];
    }

    /**
     * Mark an invoice as served on the company.
     */
    public function markInvoiceSent(int $invoiceId, string $email, ?string $messageId = null): array
    {
        $invoices = $this->fetchTable('CompanyInvoices');
        $invoice = $invoices->get($invoiceId);

        if ($invoice->status !== 'draft') {
            return ['ok' => false, 'errors' => ['invoice' => ['This invoice has already been sent.']]];
        }

        $invoice->set('status', 'sent');
        $invoice->set('sent_at', DateTime::now());
        $invoice->set('sent_to_email', $email);
        // Clause 11 recognises email only, so the Message-ID is the proof
        // of service. Without it there is no evidence the invoice was ever
        // validly delivered.
        $invoice->set('message_id', $messageId);

        $invoices->saveOrFail($invoice);

        return ['ok' => true];
    }

    /**
     * Record payment against an invoice.
     */
    public function recordInvoicePayment(int $invoiceId, int $amountPaise, ?string $reference = null): array
    {
        $invoices = $this->fetchTable('CompanyInvoices');
        $invoice = $invoices->get($invoiceId);

        $paid = (int)$invoice->paid_paise + $amountPaise;

        $invoice->set('paid_paise', $paid);
        $invoice->set('payment_reference', $reference);

        // Part payment is normal in this trade and is not the same as
        // settled, so the status distinguishes them rather than rounding
        // up to "paid" and losing the outstanding balance.
        if ($paid >= (int)$invoice->total_paise) {
            $invoice->set('status', 'paid');
            $invoice->set('paid_at', DateTime::now());
        } else {
            $invoice->set('status', 'partially_paid');
        }

        $invoices->saveOrFail($invoice);

        if ($invoice->status === 'paid') {
            $this->fetchTable('TicketCharges')->updateAll(
                ['settlement_status' => 'paid'],
                [
                    'id IN' => $this->fetchTable('CompanyInvoiceLines')->find()
                        ->select(['ticket_charge_id'])
                        ->where(['company_invoice_id' => $invoiceId, 'ticket_charge_id IS NOT' => null]),
                ],
            );
        }

        return ['ok' => true, 'paid_paise' => $paid, 'status' => $invoice->status];
    }

    /**
     * Live exposure against the company's credit limit.
     *
     * Clause 4 caps what may be outstanding. The useful moment to know is
     * before the limit is breached, not after, so this returns the headroom
     * rather than a yes/no.
     *
     * @return array<string, mixed>
     */
    public function creditExposure(int $companyId): array
    {
        $row = $this->fetchTable('CompanyInvoices')->find()
            ->select(['outstanding' => 'SUM(total_paise - paid_paise)'])
            ->where([
                'company_id' => $companyId,
                'status IN' => ['sent', 'partially_paid', 'disputed'],
            ])
            ->disableHydration()
            ->first();

        $outstanding = (int)($row['outstanding'] ?? 0);

        try {
            $limit = $this->rates->agreementTerms($companyId)->creditLimitOrDefault();
        } catch (\Cake\Datasource\Exception\RecordNotFoundException) {
            $limit = Money::zero();
        }

        return [
            'outstanding_paise' => $outstanding,
            'credit_limit_paise' => $limit->paise,
            'headroom_paise' => $limit->paise - $outstanding,
            'over_limit' => $limit->paise > 0 && $outstanding > $limit->paise,
        ];
    }

    // -----------------------------------------------------------------
    // receivables
    // -----------------------------------------------------------------

    /**
     * What every company owes us, and how far along the pipeline it is.
     *
     * Four stages, because "what are we owed" has four different answers
     * depending on who is asking and none of them is wrong:
     *
     *   unbilled  work closed and priced, sitting in `ticket_charges` with
     *             nothing claiming it. Real money, but no invoice exists,
     *             so the company has never been told about it. This is the
     *             bucket that goes unnoticed — a cycle nobody ran leaves it
     *             growing silently, and it is invisible on an invoice list.
     *   draft     on an invoice we have raised but not served. Ours to
     *             restate; not yet a claim.
     *   awaiting  served and unpaid. This is the only bucket that is
     *             legally a debt, and the only one clause 4's credit limit
     *             is measured against.
     *   received  what has actually landed.
     *
     * Summing them into one "receivable" figure would hide exactly the
     * distinction that decides what to do next: chase the company, or
     * chase ourselves.
     *
     * @param string|null $asOf Ageing reference date, defaults to today.
     * @return array<string, mixed>
     */
    public function receivables(?string $asOf = null): array
    {
        $asOf = $asOf ?? DateTime::now()->format('Y-m-d');
        $asOfTs = strtotime($asOf . ' 00:00:00');

        $companies = $this->fetchTable('Companies')->find()
            ->select(['id', 'code', 'name', 'accounts_email', 'is_active'])
            ->where(['is_active' => true])
            ->orderByAsc('name')
            ->disableHydration()
            ->all()
            ->toList();

        $unbilled = $this->unbilledByCompany();
        $invoiceRows = $this->invoiceRowsForAgeing();

        $out = [];
        foreach ($companies as $company) {
            $companyId = (int)$company['id'];

            $draft = 0;
            $awaiting = 0;
            $overdue = 0;
            $received = 0;
            $invoiceCount = 0;
            $openInvoiceCount = 0;
            $oldestDue = null;
            $ageing = ['not_due' => 0, 'd1_30' => 0, 'd31_60' => 0, 'd60_plus' => 0];

            foreach ($invoiceRows[$companyId] ?? [] as $row) {
                $invoiceCount++;
                $received += (int)$row['paid_paise'];

                $balance = (int)$row['total_paise'] - (int)$row['paid_paise'];

                if ($row['status'] === 'draft') {
                    $draft += $balance;

                    continue;
                }

                // A settled invoice contributes only to `received`. Its
                // balance is zero anyway, but a rounding-up part payment
                // could make it negative and quietly reduce the total owed.
                if (!in_array($row['status'], ['sent', 'partially_paid', 'disputed'], true)) {
                    continue;
                }

                $awaiting += $balance;
                $openInvoiceCount++;

                $dueAt = $row['due_at'];
                if ($dueAt === null) {
                    $ageing['not_due'] += $balance;

                    continue;
                }

                $dueTs = strtotime($dueAt->format('Y-m-d') . ' 00:00:00');
                $daysLate = (int)floor(($asOfTs - $dueTs) / 86400);

                if ($daysLate <= 0) {
                    $ageing['not_due'] += $balance;
                } else {
                    $overdue += $balance;
                    $bucket = $daysLate <= 30 ? 'd1_30' : ($daysLate <= 60 ? 'd31_60' : 'd60_plus');
                    $ageing[$bucket] += $balance;

                    if ($oldestDue === null || $dueTs < strtotime($oldestDue . ' 00:00:00')) {
                        $oldestDue = $dueAt->format('Y-m-d');
                    }
                }
            }

            $unbilledAmount = $unbilled[$companyId]['amount'] ?? 0;
            $unbilledTickets = $unbilled[$companyId]['tickets'] ?? 0;

            try {
                $limit = $this->rates->agreementTerms($companyId)->creditLimitOrDefault();
            } catch (\Cake\Datasource\Exception\RecordNotFoundException) {
                $limit = Money::zero();
            }

            $out[] = [
                'company' => [
                    'id' => $companyId,
                    'code' => $company['code'],
                    'name' => $company['name'],
                    'accounts_email' => $company['accounts_email'],
                ],
                'unbilled' => Money::fromPaise($unbilledAmount)->jsonSerialize(),
                'unbilled_ticket_count' => $unbilledTickets,
                'draft' => Money::fromPaise($draft)->jsonSerialize(),
                'awaiting' => Money::fromPaise($awaiting)->jsonSerialize(),
                'overdue' => Money::fromPaise($overdue)->jsonSerialize(),
                'received' => Money::fromPaise($received)->jsonSerialize(),
                // Everything earned and not yet in the bank, whatever stage
                // it has reached. The single number for "what are we owed".
                'total_due' => Money::fromPaise($unbilledAmount + $draft + $awaiting)->jsonSerialize(),
                'ageing' => [
                    'not_due' => Money::fromPaise($ageing['not_due'])->jsonSerialize(),
                    'd1_30' => Money::fromPaise($ageing['d1_30'])->jsonSerialize(),
                    'd31_60' => Money::fromPaise($ageing['d31_60'])->jsonSerialize(),
                    'd60_plus' => Money::fromPaise($ageing['d60_plus'])->jsonSerialize(),
                ],
                'oldest_overdue_due_at' => $oldestDue,
                'invoice_count' => $invoiceCount,
                'open_invoice_count' => $openInvoiceCount,
                // Clause 4 caps served, unpaid claims — not work we have
                // simply not got round to invoicing yet.
                'credit_limit' => $limit->jsonSerialize(),
                'headroom' => Money::fromPaise($limit->paise - $awaiting)->jsonSerialize(),
                'over_limit' => $limit->paise > 0 && $awaiting > $limit->paise,
            ];
        }

        return [
            'as_of' => $asOf,
            'companies' => $out,
            'totals' => $this->sumReceivables($out),
        ];
    }

    /**
     * Frozen, unclaimed company-side charge lines, per company.
     *
     * Not period-scoped: the point of this figure is everything that has
     * ever been closed and never invoiced, which is precisely the work a
     * period-scoped run would have skipped.
     *
     * @return array<int, array{amount: int, tickets: int}>
     */
    private function unbilledByCompany(): array
    {
        $rows = $this->fetchTable('TicketCharges')->find()
            ->select([
                'company_id' => 'Tickets.company_id',
                // The royalty sits on the payable ledger as a positive
                // magnitude — money flowing back the other way — so it is
                // subtracted here exactly as summariseInvoiceLines() does.
                // Summing raw amounts instead overstates every company by
                // twice its royalty.
                'amount' => 'SUM(CASE WHEN TicketCharges.ledger = \'company_payable\''
                    . ' THEN -TicketCharges.amount_paise ELSE TicketCharges.amount_paise END)',
                'tickets' => 'COUNT(DISTINCT TicketCharges.ticket_id)',
            ])
            ->join(['Tickets' => [
                'table' => 'tickets',
                'type' => 'INNER',
                'conditions' => 'Tickets.id = TicketCharges.ticket_id',
            ]])
            ->where([
                'TicketCharges.is_frozen' => true,
                'TicketCharges.settlement_status' => 'open',
                'TicketCharges.ledger IN' => ['company_receivable', 'company_payable'],
            ])
            ->groupBy('Tickets.company_id')
            ->disableHydration()
            ->all();

        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row['company_id']] = [
                'amount' => (int)$row['amount'],
                'tickets' => (int)$row['tickets'],
            ];
        }

        return $out;
    }

    /**
     * Every invoice, keyed by company, with just what ageing needs.
     *
     * Bucketed in PHP rather than in SQL. One company bills monthly, so
     * this is a row per company per month — small enough that a readable
     * loop beats a CASE expression nobody can check.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function invoiceRowsForAgeing(): array
    {
        $rows = $this->fetchTable('CompanyInvoices')->find()
            ->select(['company_id', 'status', 'total_paise', 'paid_paise', 'due_at'])
            ->disableHydration()
            ->all();

        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row['company_id']][] = $row;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $companies
     * @return array<string, mixed>
     */
    private function sumReceivables(array $companies): array
    {
        $keys = ['unbilled', 'draft', 'awaiting', 'overdue', 'received', 'total_due'];
        $sums = array_fill_keys($keys, 0);
        $tickets = 0;

        foreach ($companies as $company) {
            foreach ($keys as $key) {
                $sums[$key] += (int)$company[$key]['paise'];
            }
            $tickets += (int)$company['unbilled_ticket_count'];
        }

        $out = ['unbilled_ticket_count' => $tickets];
        foreach ($keys as $key) {
            $out[$key] = Money::fromPaise($sums[$key])->jsonSerialize();
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // technician payouts
    // -----------------------------------------------------------------

    /**
     * Build a draft payout for one technician over a period.
     *
     * @return array{ok: true, payout_id: int, payout_no: string, totals: array<string, int>}
     *        |array{ok: false, errors: array<string, list<string>>}
     */
    public function generatePayout(
        int $technicianId,
        string $periodStart,
        string $periodEnd,
        ?int $actorUserId = null,
    ): array {
        $payouts = $this->fetchTable('TechnicianPayouts');
        $connection = $payouts->getConnection();

        return $connection->transactional(
            function () use ($payouts, $technicianId, $periodStart, $periodEnd, $actorUserId): array {
                $lines = $this->claimablePayoutLines($technicianId, $periodStart, $periodEnd);

                if ($lines === []) {
                    return ['ok' => false, 'errors' => ['period' => [
                        'This technician has nothing outstanding for that period.',
                    ]]];
                }

                $totals = $this->summarisePayoutLines($lines);

                $technician = $this->fetchTable('Technicians')->get($technicianId);

                $totals['net_paise'] = $this->netPayout($totals);

                $payout = $payouts->newEntity([
                    'technician_id' => $technicianId,
                    'payout_no' => $this->nextPayoutNo($technician->code, $periodEnd),
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'status' => 'draft',
                    'gross_paise' => $totals['gross_paise'],
                    'bonus_paise' => $totals['bonus_paise'],
                    'travel_paise' => $totals['travel_paise'],
                    'penalty_recovery_paise' => $totals['penalty_recovery_paise'],
                    'deductions_paise' => $totals['deductions_paise'],
                    'net_paise' => $totals['net_paise'],
                    'ticket_count' => count(array_unique(array_column($lines, 'ticket_id'))),
                    'created_by_user_id' => $actorUserId,
                ]);

                $payouts->saveOrFail($payout);

                $payoutLines = $this->fetchTable('TechnicianPayoutLines');
                $sort = 0;

                foreach ($lines as $line) {
                    $payoutLines->saveOrFail($payoutLines->newEntity([
                        'technician_payout_id' => $payout->id,
                        'ticket_charge_id' => (int)$line['id'],
                        'ticket_id' => (int)$line['ticket_id'],
                        'line_type' => (string)$line['line_type'],
                        'description' => sprintf('%s — %s', $line['ticket_no'], $line['description']),
                        'amount_paise' => $line['amount_paise'],
                        'sort_order' => $sort++,
                    ]));
                }

                // Technician lines are claimed by being attached to a payout
                // rather than by a status flag, because the same ticket
                // charge is never on two payouts: the join row is the claim.
                return [
                    'ok' => true,
                    'payout_id' => (int)$payout->id,
                    'payout_no' => (string)$payout->payout_no,
                    'totals' => $totals,
                ];
            },
        );
    }

    /**
     * Frozen technician-side lines not yet on any payout.
     *
     * @return list<array<string, mixed>>
     */
    private function claimablePayoutLines(int $technicianId, string $periodStart, string $periodEnd): array
    {
        $alreadyPaid = $this->fetchTable('TechnicianPayoutLines')->find()
            ->select(['ticket_charge_id'])
            ->where(['ticket_charge_id IS NOT' => null]);

        return $this->fetchTable('TicketCharges')->find()
            ->select([
                'TicketCharges.id',
                'TicketCharges.ticket_id',
                'TicketCharges.line_type',
                'TicketCharges.description',
                'TicketCharges.amount_paise',
                'ticket_no' => 'Tickets.ticket_no',
            ])
            ->join(['Tickets' => [
                'table' => 'tickets',
                'type' => 'INNER',
                'conditions' => 'Tickets.id = TicketCharges.ticket_id',
            ]])
            ->where([
                'Tickets.assigned_technician_id' => $technicianId,
                'Tickets.closed_at >=' => $periodStart . ' 00:00:00',
                'Tickets.closed_at <=' => $periodEnd . ' 23:59:59',
                'TicketCharges.is_frozen' => true,
                'TicketCharges.ledger' => 'technician_payable',
                'TicketCharges.id NOT IN' => $alreadyPaid,
            ])
            ->orderByAsc('Tickets.closed_at')
            ->disableHydration()
            ->all()
            ->toList();
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array<string, int>
     */
    private function summarisePayoutLines(array $lines): array
    {
        $totals = [
            'gross_paise' => 0,
            'bonus_paise' => 0,
            'travel_paise' => 0,
            'penalty_recovery_paise' => 0,
            'deductions_paise' => 0,
            'net_paise' => 0,
        ];

        foreach ($lines as $line) {
            $amount = (int)$line['amount_paise'];

            match ((string)$line['line_type']) {
                'technician_payout' => $totals['gross_paise'] += $amount,
                'technician_bonus_share' => $totals['bonus_paise'] += $amount,
                // Already negative, so it reduces the net by summing.
                'technician_penalty_recovery' => $totals['penalty_recovery_paise'] += $amount,
                'travel' => $totals['travel_paise'] += $amount,
                default => $totals['gross_paise'] += $amount,
            };
        }

        return $totals;
    }

    /**
     * Approve a payout, which is the point it stops being editable.
     */
    public function approvePayout(int $payoutId, ?int $actorUserId = null): array
    {
        $payouts = $this->fetchTable('TechnicianPayouts');
        $payout = $payouts->get($payoutId);

        if ($payout->status !== 'draft') {
            return ['ok' => false, 'errors' => ['payout' => ['Only a draft payout can be approved.']]];
        }

        $payout->set('status', 'approved');
        $payout->set('approved_at', DateTime::now());
        $payout->set('approved_by_user_id', $actorUserId);

        $payouts->saveOrFail($payout);

        return ['ok' => true];
    }

    public function markPayoutPaid(int $payoutId, string $method, ?string $reference = null): array
    {
        $payouts = $this->fetchTable('TechnicianPayouts');
        $payout = $payouts->get($payoutId);

        // Approval is a separate person's decision from payment on purpose;
        // paying an unapproved run removes the only control on this ledger.
        if ($payout->status !== 'approved') {
            return ['ok' => false, 'errors' => ['payout' => ['This payout has not been approved yet.']]];
        }

        $payout->set('status', 'paid');
        $payout->set('paid_at', DateTime::now());
        $payout->set('payment_method', $method);
        $payout->set('payment_reference', $reference);

        $payouts->saveOrFail($payout);

        return ['ok' => true];
    }

    /**
     * One payout, broken into the parts that make up the net.
     *
     * Same shape as {@see self::invoiceDetail()} so the desk reads one
     * layout on both tabs, and the client renders one component.
     *
     * @return array<string, mixed>|null Null when there is no such payout.
     */
    public function payoutDetail(int $payoutId): ?array
    {
        $payout = $this->fetchTable('TechnicianPayouts')->find()
            ->contain(['Technicians'])
            ->where(['TechnicianPayouts.id' => $payoutId])
            ->first();

        if ($payout === null) {
            return null;
        }

        $rows = $this->fetchTable('TechnicianPayoutLines')->find()
            ->select([
                'TechnicianPayoutLines.id',
                'TechnicianPayoutLines.ticket_id',
                'TechnicianPayoutLines.ticket_charge_id',
                'TechnicianPayoutLines.line_type',
                'TechnicianPayoutLines.description',
                'TechnicianPayoutLines.amount_paise',
                'TechnicianPayoutLines.original_amount_paise',
                'TechnicianPayoutLines.override_reason',
                'TechnicianPayoutLines.overridden_at',
                'ticket_no' => 'Tickets.ticket_no',
                'overridden_by' => 'OverriddenByUsers.name',
            ])
            ->leftJoinWith('Tickets')
            ->leftJoinWith('OverriddenByUsers')
            ->where(['TechnicianPayoutLines.technician_payout_id' => $payoutId])
            ->orderByAsc('TechnicianPayoutLines.sort_order')
            ->orderByAsc('TechnicianPayoutLines.id')
            ->disableHydration()
            ->all()
            ->toList();

        $totals = $this->summarisePayoutLines($rows);
        $totals['net_paise'] = $this->netPayout($totals);

        // Approval is the point a payout stops being a working figure, so
        // it is also the point the lines stop being editable.
        $isEditable = $payout->status === 'draft';

        return [
            'id' => (int)$payout->id,
            'payout_no' => $payout->payout_no,
            'status' => $payout->status,
            'technician' => $payout->technician === null ? null : [
                'id' => (int)$payout->technician->id,
                'code' => $payout->technician->code,
                'name' => $payout->technician->name,
            ],
            'period_start' => $payout->period_start->format('Y-m-d'),
            'period_end' => $payout->period_end->format('Y-m-d'),
            'ticket_count' => (int)$payout->ticket_count,
            'is_editable' => $isEditable,
            'locked_reason' => $isEditable ? null : sprintf(
                'This payout is %s and can no longer be edited.',
                $payout->status,
            ),
            'split' => $this->labelledPayoutSplit($totals),
            'totals' => [
                'total' => Money::fromPaise($totals['net_paise'])->jsonSerialize(),
                'line_count' => count($rows),
            ],
            // Paid by us, out of the margin between what the company is
            // invoiced and what the technician earned — the company never
            // pays a technician directly. So how the money left our hands
            // is the only trace the payment leaves.
            'payment' => [
                'method' => $payout->payment_method,
                'method_label' => $payout->payment_method === null
                    ? null
                    : PayoutMethod::tryFrom($payout->payment_method)?->label() ?? $payout->payment_method,
                'reference' => $payout->payment_reference,
                'paid_at' => $payout->paid_at?->format('Y-m-d H:i'),
                'approved_at' => $payout->approved_at?->format('Y-m-d H:i'),
            ],
            'can_approve' => $payout->status === 'draft',
            'can_pay' => $payout->status === 'approved',
            'payment_methods' => PayoutMethod::options(),
            'tickets' => $this->groupLinesByTicket($rows),
        ];
    }

    /**
     * Restate one line on a draft payout. See {@see self::overrideInvoiceLine()}.
     *
     * @return array{ok: true, payout: array<string, mixed>}
     *        |array{ok: false, code: string, errors: array<string, list<string>>}
     */
    public function overridePayoutLine(
        int $payoutId,
        int $lineId,
        string $amount,
        string $reason,
        ?int $actorUserId = null,
    ): array {
        $guard = $this->guardPayoutLine($payoutId, $lineId);
        if ($guard !== null) {
            return $guard;
        }

        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'code' => 'validation_error', 'errors' => ['reason' => [
                'An override needs a reason. A technician who queries their '
                . 'payslip is owed one.',
            ]]];
        }

        try {
            $money = Money::parse(trim($amount));
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'code' => 'validation_error', 'errors' => ['amount' => [$e->getMessage()]]];
        }

        $lines = $this->fetchTable('TechnicianPayoutLines');
        $line = $lines->get($lineId);

        if ($line->original_amount_paise === null) {
            $line->set('original_amount_paise', (int)$line->amount_paise);
        }

        $line->set('amount_paise', $money->paise);
        $line->set('override_reason', $reason);
        $line->set('overridden_at', DateTime::now());
        $line->set('overridden_by_user_id', $actorUserId);

        if (!$lines->save($line)) {
            return ['ok' => false, 'code' => 'validation_error', 'errors' => $line->getErrors()];
        }

        $this->recalculatePayout($payoutId);

        return ['ok' => true, 'payout' => (array)$this->payoutDetail($payoutId)];
    }

    /**
     * Put a restated payout line back to the amount the run raised it at.
     *
     * @return array{ok: true, payout: array<string, mixed>}
     *        |array{ok: false, code: string, errors: array<string, list<string>>}
     */
    public function resetPayoutLine(int $payoutId, int $lineId): array
    {
        $guard = $this->guardPayoutLine($payoutId, $lineId);
        if ($guard !== null) {
            return $guard;
        }

        $lines = $this->fetchTable('TechnicianPayoutLines');
        $line = $lines->get($lineId);

        if ($line->original_amount_paise !== null) {
            $line->set('amount_paise', (int)$line->original_amount_paise);
            $line->set('original_amount_paise', null);
            $line->set('override_reason', null);
            $line->set('overridden_at', null);
            $line->set('overridden_by_user_id', null);

            $lines->saveOrFail($line);
            $this->recalculatePayout($payoutId);
        }

        return ['ok' => true, 'payout' => (array)$this->payoutDetail($payoutId)];
    }

    /**
     * @return array{ok: false, code: string, errors: array<string, list<string>>}|null
     */
    private function guardPayoutLine(int $payoutId, int $lineId): ?array
    {
        $line = $this->fetchTable('TechnicianPayoutLines')->find()
            ->where(['id' => $lineId, 'technician_payout_id' => $payoutId])
            ->first();

        if ($line === null) {
            return ['ok' => false, 'code' => 'not_found', 'errors' => ['line_id' => [
                'That line is not on this payout.',
            ]]];
        }

        $payout = $this->fetchTable('TechnicianPayouts')->get($payoutId);

        if ($payout->status !== 'draft') {
            return ['ok' => false, 'code' => 'invalid_state', 'errors' => ['payout' => [sprintf(
                'This payout is %s and can no longer be edited.',
                $payout->status,
            )]]];
        }

        return null;
    }

    /**
     * Re-derive the payout header from its lines. See the invoice twin.
     */
    private function recalculatePayout(int $payoutId): void
    {
        $rows = $this->fetchTable('TechnicianPayoutLines')->find()
            ->select(['line_type', 'amount_paise', 'ticket_id'])
            ->where(['technician_payout_id' => $payoutId])
            ->disableHydration()
            ->all()
            ->toList();

        $totals = $this->summarisePayoutLines($rows);
        $totals['net_paise'] = $this->netPayout($totals);

        $payouts = $this->fetchTable('TechnicianPayouts');
        $payout = $payouts->get($payoutId);

        foreach ($totals as $column => $value) {
            $payout->set($column, $value);
        }

        $payout->set('ticket_count', count(array_filter(
            array_unique(array_column($rows, 'ticket_id')),
            fn ($id): bool => $id !== null,
        )));

        $payouts->saveOrFail($payout);
    }

    /**
     * The one place the net is derived, so the run and a later restatement
     * cannot disagree about what a technician is owed.
     *
     * @param array<string, int> $totals
     */
    private function netPayout(array $totals): int
    {
        return $totals['gross_paise']
            + $totals['bonus_paise']
            + $totals['travel_paise']
            + $totals['penalty_recovery_paise']
            - $totals['deductions_paise'];
    }

    /**
     * @param array<string, int> $totals
     * @return list<array<string, mixed>>
     */
    private function labelledPayoutSplit(array $totals): array
    {
        $buckets = [
            ['key' => 'gross', 'label' => 'Job earnings', 'paise' => $totals['gross_paise'], 'sign' => 1],
            ['key' => 'bonus', 'label' => 'SLA bonus share', 'paise' => $totals['bonus_paise'], 'sign' => 1],
            ['key' => 'travel', 'label' => 'Travel', 'paise' => $totals['travel_paise'], 'sign' => 1],
            [
                'key' => 'penalty_recovery',
                'label' => 'Penalty recovery',
                'paise' => $totals['penalty_recovery_paise'],
                'sign' => 1,
            ],
            ['key' => 'deductions', 'label' => 'Deductions', 'paise' => $totals['deductions_paise'], 'sign' => -1],
        ];

        return array_map(
            fn (array $bucket): array => [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'sign' => $bucket['sign'],
                'amount' => Money::fromPaise($bucket['paise'])->jsonSerialize(),
                'effect' => Money::fromPaise($bucket['sign'] * $bucket['paise'])->jsonSerialize(),
            ],
            $buckets,
        );
    }

    // -----------------------------------------------------------------
    // numbering
    // -----------------------------------------------------------------

    private function nextInvoiceNo(int $companyId, string $periodEnd): string
    {
        $company = $this->fetchTable('Companies')->get($companyId);
        $period = (new DateTime($periodEnd))->format('Ym');

        $count = $this->fetchTable('CompanyInvoices')->find()
            ->where(['company_id' => $companyId, 'invoice_no LIKE' => sprintf('INV-%s-%s-%%', $company->code, $period)])
            ->count();

        return sprintf('INV-%s-%s-%02d', $company->code, $period, $count + 1);
    }

    private function nextPayoutNo(string $technicianCode, string $periodEnd): string
    {
        $period = (new DateTime($periodEnd))->format('Ym');

        $count = $this->fetchTable('TechnicianPayouts')->find()
            ->where(['payout_no LIKE' => sprintf('PAY-%s-%s-%%', $technicianCode, $period)])
            ->count();

        return sprintf('PAY-%s-%s-%02d', $technicianCode, $period, $count + 1);
    }

    /**
     * The company's settlement date following a period.
     *
     * Clause 5 finalises Dianora's invoices on the 10th; each company sets
     * its own day, and a period ending after that day settles in the
     * following month rather than in a date already past.
     */
    private function cycleDateAfter(string $periodEnd, int $cycleDay): string
    {
        $end = new DateTime($periodEnd);
        // Clamped to 28 so "the 30th" does not silently skip February.
        $cycleDay = max(1, min(28, $cycleDay));

        // Two modify() calls, not one combined relative string: PHP parses
        // "first day of next month +9 days" without error and then ignores
        // the offset, which produced settlement dates on the 1st of the
        // month for a company that settles on the 10th.
        $candidate = $end->modify('first day of this month')->modify(sprintf('+%d days', $cycleDay - 1));

        if ($candidate <= $end) {
            $candidate = $end->modify('first day of next month')->modify(sprintf('+%d days', $cycleDay - 1));
        }

        return $candidate->format('Y-m-d');
    }
}
