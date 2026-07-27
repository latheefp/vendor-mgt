<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * VendorInvoiceLine Entity
 *
 * @property int $id
 * @property int $vendor_invoice_id
 * @property int|null $ticket_charge_id
 * @property int|null $ticket_id
 * @property string $line_type
 * @property string|null $ledger
 * @property string $description
 * @property string|null $quantity
 * @property int|null $unit_amount_paise
 * @property int $amount_paise
 * @property int|null $original_amount_paise
 * @property string|null $override_reason
 * @property \Cake\I18n\DateTime|null $overridden_at
 * @property int|null $overridden_by_user_id
 * @property int $sort_order
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\VendorInvoice $vendor_invoice
 * @property \App\Model\Entity\TicketCharge $ticket_charge
 * @property \App\Model\Entity\Ticket $ticket
 * @property \App\Model\Entity\User $overridden_by_user
 */
class VendorInvoiceLine extends Entity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * Note that when '*' is set to true, this allows all unspecified fields to
     * be mass assigned. For security purposes, it is advised to set '*' to false
     * (or remove it), and explicitly make individual fields accessible as needed.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'vendor_invoice_id' => true,
        'ticket_charge_id' => true,
        'ticket_id' => true,
        'line_type' => true,
        'ledger' => true,
        'description' => true,
        'quantity' => true,
        'unit_amount_paise' => true,
        'amount_paise' => true,
        'original_amount_paise' => true,
        'override_reason' => true,
        'overridden_at' => true,
        'overridden_by_user_id' => true,
        'sort_order' => true,
        'created' => true,
        'modified' => true,
        'vendor_invoice' => true,
        'ticket_charge' => true,
        'ticket' => true,
        'overridden_by_user' => true,
    ];

    /**
     * Whether this line was restated by hand after the run.
     *
     * Keyed off `original_amount_paise` rather than a boolean column: the
     * original is what makes the restatement reconcilable, so a line that
     * has one is by definition overridden, and the two can never disagree.
     */
    protected function _getIsOverridden(): bool
    {
        return $this->original_amount_paise !== null;
    }
}
