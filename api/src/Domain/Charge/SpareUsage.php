<?php
declare(strict_types=1);

namespace App\Domain\Charge;

use App\Domain\Enum\Payer;
use App\Domain\Money;

/**
 * A spare part consumed on a ticket.
 *
 * Only customer-billed spares produce charge lines. In-warranty parts are
 * supplied by the company and carry no money for us — they carry an
 * obligation instead: the defective unit goes back inside 7 days
 * (clause 9), and anything still on our shelf after 30 days is treated as
 * billed to us (clause 10). Those clocks live on the ticket_spares row,
 * not here.
 */
final readonly class SpareUsage
{
    public function __construct(
        public int $id,
        public string $partNo,
        public string $name,
        public int $quantity,
        public Money $unitCost,
        /** Margin applied, as a decimal percent string within the agreed band. */
        public string $marginPct = '0.00',
        public Payer $chargedTo = Payer::Company,
    ) {
    }

    /**
     * What the part costs us, before margin.
     */
    public function costTotal(): Money
    {
        return $this->unitCost->times($this->quantity);
    }

    /**
     * Clause 6: "TOTAL COST = SPARE PART COST + 10% to 15% margin".
     */
    public function marginTotal(): Money
    {
        return $this->costTotal()->percentage($this->marginPct);
    }

    /**
     * What the customer pays for this line.
     */
    public function priceTotal(): Money
    {
        return $this->costTotal()->plus($this->marginTotal());
    }

    public function isBilledToCustomer(): bool
    {
        return $this->chargedTo === Payer::Customer;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ticket_spare_id' => $this->id,
            'part_no' => $this->partNo,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'unit_cost_paise' => $this->unitCost->paise,
            'margin_pct' => $this->marginPct,
            'cost_total_paise' => $this->costTotal()->paise,
            'margin_total_paise' => $this->marginTotal()->paise,
            'price_total_paise' => $this->priceTotal()->paise,
            'charged_to' => $this->chargedTo->value,
        ];
    }
}
