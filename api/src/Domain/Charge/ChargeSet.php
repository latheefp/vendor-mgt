<?php
declare(strict_types=1);

namespace App\Domain\Charge;

use App\Domain\Enum\ChargeLineType;
use App\Domain\Enum\Ledger;
use App\Domain\Money;

/**
 * All the money for one ticket.
 *
 * The aggregation methods here are the reason the ledgers were separated
 * in the first place: gross margin per job stops being a spreadsheet
 * exercise and becomes `$set->grossMargin()`.
 */
final readonly class ChargeSet
{
    /**
     * @param list<ChargeLine> $lines
     */
    public function __construct(public array $lines = [])
    {
    }

    public function withLine(ChargeLine $line): self
    {
        return new self([...$this->lines, $line]);
    }

    /**
     * @param list<ChargeLine> $lines
     */
    public function withLines(array $lines): self
    {
        return new self([...$this->lines, ...$lines]);
    }

    /**
     * @return list<ChargeLine>
     */
    public function forLedger(Ledger $ledger): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (ChargeLine $l): bool => $l->ledger === $ledger,
        ));
    }

    /**
     * @return list<ChargeLine>
     */
    public function ofType(ChargeLineType $type): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (ChargeLine $l): bool => $l->type === $type,
        ));
    }

    public function totalFor(Ledger $ledger): Money
    {
        return Money::sum(array_map(
            static fn (ChargeLine $l): Money => $l->amount,
            $this->forLedger($ledger),
        ));
    }

    /** Net of any SLA deduction, since penalties sit on this ledger as negatives. */
    public function vendorReceivable(): Money
    {
        return $this->totalFor(Ledger::VendorReceivable);
    }

    /** Cash the technician must collect at the door and later bank. */
    public function customerCollection(): Money
    {
        return $this->totalFor(Ledger::CustomerCollection);
    }

    /** What we owe the vendor back — the out-of-warranty royalty. */
    public function vendorPayable(): Money
    {
        return $this->totalFor(Ledger::VendorPayable);
    }

    public function technicianPayable(): Money
    {
        return $this->totalFor(Ledger::TechnicianPayable);
    }

    /**
     * What the job actually earned us.
     */
    public function grossMargin(): Money
    {
        return $this->vendorReceivable()
            ->plus($this->customerCollection())
            ->minus($this->vendorPayable())
            ->minus($this->technicianPayable());
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function count(): int
    {
        return count($this->lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'vendor_receivable' => $this->vendorReceivable()->jsonSerialize(),
            'customer_collection' => $this->customerCollection()->jsonSerialize(),
            'vendor_payable' => $this->vendorPayable()->jsonSerialize(),
            'technician_payable' => $this->technicianPayable()->jsonSerialize(),
            'gross_margin' => $this->grossMargin()->jsonSerialize(),
            'line_count' => $this->count(),
        ];
    }
}
