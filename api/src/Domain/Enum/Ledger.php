<?php
declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * The four books a charge line can land on.
 *
 * Keeping them apart is the whole point of the ledger design. A single
 * out-of-warranty panel job touches all four at once: we collect Rs.1500
 * from the customer, we owe the vendor Rs.150 royalty on it, we owe the
 * technician their share, and none of that belongs in the same column as
 * an in-warranty receivable.
 */
enum Ledger: string
{
    /** Money the vendor owes us — in-warranty work, installations, travel. */
    case VendorReceivable = 'vendor_receivable';

    /** Cash the technician collects from the customer for out-of-warranty work. */
    case CustomerCollection = 'customer_collection';

    /** Money we owe the vendor — the royalty on out-of-warranty collections. */
    case VendorPayable = 'vendor_payable';

    /** Money we owe the technician. */
    case TechnicianPayable = 'technician_payable';

    public function label(): string
    {
        return match ($this) {
            self::VendorReceivable => 'Vendor receivable',
            self::CustomerCollection => 'Collected from customer',
            self::VendorPayable => 'Payable to vendor',
            self::TechnicianPayable => 'Payable to technician',
        };
    }

    /**
     * Whether a positive amount on this ledger increases our income.
     * Used by the margin calculation, which nets the inflows against the
     * outflows rather than trusting a sign convention.
     */
    public function isInflow(): bool
    {
        return match ($this) {
            self::VendorReceivable, self::CustomerCollection => true,
            self::VendorPayable, self::TechnicianPayable => false,
        };
    }
}
