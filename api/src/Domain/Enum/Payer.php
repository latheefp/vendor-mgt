<?php
declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Who physically hands over the money for a rate card line.
 *
 * On the Dianora card installation and in-warranty service are billed to
 * the vendor, while out-of-warranty service is collected from the
 * customer at the door. The difference drives which ledger the base line
 * lands on, whether a royalty is due, and whether a cash-collection
 * record and its deposit reconciliation are required before the ticket
 * can be considered settled.
 */
enum Payer: string
{
    case Vendor = 'vendor';
    case Customer = 'customer';

    public function ledger(): Ledger
    {
        return match ($this) {
            self::Vendor => Ledger::VendorReceivable,
            self::Customer => Ledger::CustomerCollection,
        };
    }

    /**
     * Out-of-warranty money arrives as cash or UPI in a technician's
     * pocket, which has to be banked and reconciled.
     */
    public function requiresCashCollection(): bool
    {
        return $this === self::Customer;
    }

    public function label(): string
    {
        return match ($this) {
            self::Vendor => 'Vendor',
            self::Customer => 'Customer',
        };
    }
}
