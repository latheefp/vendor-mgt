<?php
declare(strict_types=1);

namespace App\Domain\Charge;

use App\Domain\Enum\ChargeLineType;
use App\Domain\Enum\Ledger;
use App\Domain\Money;

/**
 * One line of frozen money.
 *
 * Sign convention, fixed here and relied on everywhere downstream:
 *
 *   Amounts are POSITIVE magnitudes on their own ledger. A company payable
 *   of Rs.150 means we owe the company Rs.150; it is not stored as -150 on
 *   the receivable ledger. `Ledger::isInflow()` supplies direction when
 *   the margin is computed.
 *
 *   The one exception is an SLA penalty, which is NEGATIVE on the company
 *   receivable ledger, because that is what it genuinely is: a deduction
 *   from what the company owes for that job, not a separate debt. Printing
 *   it as a negative line on the invoice is also what the company expects
 *   to see.
 *
 * `sourceRefs` is what makes a line defensible. Every amount points back
 * at the rate card item or SLA rule that produced it, and `snapshot`
 * holds the inputs, so an amount queried in six months can be replayed
 * rather than re-argued.
 */
final readonly class ChargeLine
{
    /**
     * @param array<string, int|null> $sourceRefs
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        public ChargeLineType $type,
        public Ledger $ledger,
        public string $description,
        public Money $amount,
        public ?string $quantity = null,
        public ?Money $unitAmount = null,
        public array $sourceRefs = [],
        public array $snapshot = [],
    ) {
    }

    public function rateCardItemId(): ?int
    {
        return $this->sourceRefs['rate_card_item_id'] ?? null;
    }

    public function slaRuleId(): ?int
    {
        return $this->sourceRefs['sla_rule_id'] ?? null;
    }

    public function technicianRateId(): ?int
    {
        return $this->sourceRefs['technician_rate_id'] ?? null;
    }

    public function ticketSpareId(): ?int
    {
        return $this->sourceRefs['ticket_spare_id'] ?? null;
    }

    /**
     * Row shape for the ticket_charges table.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'line_type' => $this->type->value,
            'ledger' => $this->ledger->value,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_amount_paise' => $this->unitAmount?->paise,
            'amount_paise' => $this->amount->paise,
            'rate_card_item_id' => $this->rateCardItemId(),
            'sla_rule_id' => $this->slaRuleId(),
            'technician_rate_id' => $this->technicianRateId(),
            'ticket_spare_id' => $this->ticketSpareId(),
            'calc_snapshot' => $this->snapshot,
        ];
    }
}
