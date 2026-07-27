<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * VendorAgreement Entity
 *
 * @property int $id
 * @property int $vendor_id
 * @property string $agreement_no
 * @property string|null $title
 * @property string $status
 * @property \Cake\I18n\Date|null $signed_on
 * @property \Cake\I18n\Date $effective_from
 * @property \Cake\I18n\Date|null $effective_to
 * @property int $credit_limit_paise
 * @property int $invoice_cycle_day
 * @property string $oow_royalty_pct
 * @property string $spare_margin_min_pct
 * @property string $spare_margin_max_pct
 * @property string $travel_free_km
 * @property int $travel_rate_per_km_paise
 * @property int $sla_contact_hours
 * @property int $sla_visit_hours
 * @property int $sla_close_hours
 * @property int $repeat_complaint_window_days
 * @property int $defective_return_days
 * @property int $spare_billing_days
 * @property int $notice_period_days
 * @property string|null $document_path
 * @property string|null $notes
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Vendor $vendor
 * @property \App\Model\Entity\RateCard[] $rate_cards
 * @property \App\Model\Entity\TicketCharge[] $ticket_charges
 * @property \App\Model\Entity\Ticket[] $tickets
 * @property \App\Model\Entity\VendorInvoice[] $vendor_invoices
 */
class VendorAgreement extends Entity
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
        'agreement_no' => true,
        'title' => true,
        'status' => true,
        'signed_on' => true,
        'effective_from' => true,
        'effective_to' => true,
        'credit_limit_paise' => true,
        'invoice_cycle_day' => true,
        'oow_royalty_pct' => true,
        'spare_margin_min_pct' => true,
        'spare_margin_max_pct' => true,
        'travel_free_km' => true,
        'travel_rate_per_km_paise' => true,
        'sla_contact_hours' => true,
        'sla_visit_hours' => true,
        'sla_close_hours' => true,
        'repeat_complaint_window_days' => true,
        'defective_return_days' => true,
        'spare_billing_days' => true,
        'notice_period_days' => true,
        'document_path' => true,
        'notes' => true,
        'created' => true,
        'modified' => true,
        'vendor' => true,
        'rate_cards' => true,
        'ticket_charges' => true,
        'tickets' => true,
        'vendor_invoices' => true,
    ];
}
