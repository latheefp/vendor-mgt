<?php
declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * The four books a charge line can land on.
 *
 * Keeping them apart is the whole point of the ledger design. A single
 * out-of-warranty panel job touches all four at once: we collect Rs.1500
 * from the customer, we owe the company Rs.150 royalty on it, we owe the
 * technician their share, and none of that belongs in the same column as
 * an in-warranty receivable.
 *
 * WHO IS WHO. "Company" is the manufacturer whose warranty work we
 * perform — Dianora today. WE are the service centre. The agreement uses
 * exactly those two words for exactly those two parties, and the ledger
 * follows it, because a receivable is only unambiguous if both sides read
 * the same noun for the same party.
 */
enum Ledger: string
{
    /** Money the company owes us — in-warranty work, installations, travel. */
    case CompanyReceivable = 'company_receivable';

    /** Cash the technician collects from the customer for out-of-warranty work. */
    case CustomerCollection = 'customer_collection';

    /** Money we owe the company — the royalty on out-of-warranty collections. */
    case CompanyPayable = 'company_payable';

    /** Money we owe the technician. */
    case TechnicianPayable = 'technician_payable';

    /**
     * The generic label, for pickers and column headings where no one
     * ticket — and so no one company — is in view.
     */
    public function label(): string
    {
        return match ($this) {
            self::CompanyReceivable => 'Company receivable',
            self::CustomerCollection => 'Collected from customer',
            self::CompanyPayable => 'Payable to company',
            self::TechnicianPayable => 'Payable to technician',
        };
    }

    /**
     * The same label with the counterparty named.
     *
     * Worth the extra argument on anything a second party might read. A
     * frozen charge sheet showing "Payable to company" still asks the
     * reader to know which of the two companies on the page is meant;
     * "Payable to Dianora" does not, and it is the line that survives
     * being forwarded, printed or attached to a disputed invoice.
     *
     * Falls back to the generic wording when the name is missing, rather
     * than printing an empty gap where a party should be.
     */
    public function labelFor(?string $companyName): string
    {
        if ($companyName === null || trim($companyName) === '') {
            return $this->label();
        }

        return match ($this) {
            self::CompanyReceivable => sprintf('Billed to %s', $companyName),
            self::CompanyPayable => sprintf('Payable to %s', $companyName),
            self::CustomerCollection, self::TechnicianPayable => $this->label(),
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
            self::CompanyReceivable, self::CustomerCollection => true,
            self::CompanyPayable, self::TechnicianPayable => false,
        };
    }
}
