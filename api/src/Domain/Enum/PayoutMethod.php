<?php
declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * How a technician's money actually reached them.
 *
 * A technician is paid by US, never by the company. The company settles
 * its invoice with us; what we owe the technician comes out of the margin
 * between the two, which is why `technician_payable` is its own ledger and
 * why a payout run is separate from an invoice run.
 *
 * That makes the method the only external evidence a payout happened.
 * An invoice has an email and a Message-ID behind it; a payout paid in
 * cash has nothing but this field and a reference, so the set is closed
 * rather than free text — "gpay", "GPay" and "g-pay" in the same column
 * cannot be reconciled against a bank statement.
 */
enum PayoutMethod: string
{
    /** Handed over directly. The reference is the voucher number. */
    case Cash = 'cash';

    /** UPI. The reference is the UPI transaction ID. */
    case Gpay = 'gpay';

    /** NEFT/IMPS to the technician's account. The reference is the UTR. */
    case BankTransfer = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Gpay => 'GPay / UPI',
            self::BankTransfer => 'Bank transfer',
        };
    }

    /**
     * What the reference field should hold for this method. Shown as the
     * input's placeholder, because "reference" alone gets a blank field
     * and a blank field is what makes a payment untraceable later.
     */
    public function referenceLabel(): string
    {
        return match ($this) {
            self::Cash => 'Voucher no.',
            self::Gpay => 'UPI transaction ID',
            self::BankTransfer => 'UTR / bank reference',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string, reference_label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $m): array => [
                'value' => $m->value,
                'label' => $m->label(),
                'reference_label' => $m->referenceLabel(),
            ],
            self::cases(),
        );
    }
}
