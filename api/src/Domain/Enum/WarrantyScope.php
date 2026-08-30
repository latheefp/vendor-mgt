<?php
declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Whether a job is priced under warranty, outside it, or on a rate that
 * has no warranty dimension at all.
 *
 * This distinction decides who pays. In-warranty work is billed to the
 * company; out-of-warranty work is collected in cash from the customer by
 * the technician and attracts the company's royalty. Getting it wrong does
 * not just misprice a line, it bills the wrong party.
 */
enum WarrantyScope: string
{
    case InWarranty = 'in_warranty';
    case OutOfWarranty = 'out_of_warranty';

    /**
     * For jobs where warranty is irrelevant to the rate — installation and
     * demo are paid the same either way.
     */
    case NotApplicable = 'not_applicable';

    /**
     * Set on intake when the company has not told us and we have not yet
     * checked the serial. A ticket must never be rated in this state: the
     * resolver refuses it rather than guessing which party to bill.
     */
    case Unknown = 'unknown';

    public function isRateable(): bool
    {
        return $this !== self::Unknown;
    }

    public function label(): string
    {
        return match ($this) {
            self::InWarranty => 'In warranty',
            self::OutOfWarranty => 'Out of warranty',
            self::NotApplicable => 'Not applicable',
            self::Unknown => 'Unknown',
        };
    }
}
