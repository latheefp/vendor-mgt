<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * VendorInvoice Entity
 *
 * @property int $id
 * @property int $vendor_id
 * @property int|null $vendor_agreement_id
 * @property string $invoice_no
 * @property \Cake\I18n\Date $period_start
 * @property \Cake\I18n\Date $period_end
 * @property \Cake\I18n\Date|null $cycle_date
 * @property string $status
 * @property int $subtotal_paise
 * @property int $sla_bonus_paise
 * @property int $sla_penalty_paise
 * @property int $travel_paise
 * @property int $spare_paise
 * @property int $royalty_paise
 * @property int $total_paise
 * @property int $paid_paise
 * @property int $ticket_count
 * @property \Cake\I18n\DateTime|null $sent_at
 * @property string|null $sent_to_email
 * @property string|null $message_id
 * @property \Cake\I18n\Date|null $due_at
 * @property \Cake\I18n\DateTime|null $paid_at
 * @property string|null $payment_reference
 * @property \Cake\I18n\DateTime|null $disputed_at
 * @property string|null $dispute_notes
 * @property string|null $pdf_path
 * @property int|null $created_by_user_id
 * @property string|null $notes
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Vendor $vendor
 * @property \App\Model\Entity\VendorAgreement $vendor_agreement
 * @property \App\Model\Entity\User $created_by_user
 * @property \App\Model\Entity\VendorInvoiceLine[] $vendor_invoice_lines
 */
class VendorInvoice extends Entity
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
        'vendor_id' => true,
        'vendor_agreement_id' => true,
        'invoice_no' => true,
        'period_start' => true,
        'period_end' => true,
        'cycle_date' => true,
        'status' => true,
        'subtotal_paise' => true,
        'sla_bonus_paise' => true,
        'sla_penalty_paise' => true,
        'travel_paise' => true,
        'spare_paise' => true,
        'royalty_paise' => true,
        'total_paise' => true,
        'paid_paise' => true,
        'ticket_count' => true,
        'sent_at' => true,
        'sent_to_email' => true,
        'message_id' => true,
        'due_at' => true,
        'paid_at' => true,
        'payment_reference' => true,
        'disputed_at' => true,
        'dispute_notes' => true,
        'pdf_path' => true,
        'created_by_user_id' => true,
        'notes' => true,
        'created' => true,
        'modified' => true,
        'vendor' => true,
        'vendor_agreement' => true,
        'created_by_user' => true,
        'vendor_invoice_lines' => true,
    ];
}
