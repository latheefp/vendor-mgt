<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Ticket Entity
 *
 * @property int $id
 * @property string $ticket_no
 * @property int $company_id
 * @property string|null $company_ticket_ref
 * @property int|null $company_agreement_id
 * @property int|null $rate_card_id
 * @property int $service_center_id
 * @property int $customer_id
 * @property int|null $product_id
 * @property int|null $product_category_id
 * @property string|null $model_no
 * @property string|null $serial_no
 * @property string|null $size_inch
 * @property \Cake\I18n\Date|null $purchase_date
 * @property string $warranty_scope
 * @property int $job_type_id
 * @property string $status
 * @property string $priority
 * @property string|null $reported_issue
 * @property string|null $diagnosis
 * @property string|null $closure_notes
 * @property int|null $assigned_technician_id
 * @property \Cake\I18n\DateTime|null $assigned_at
 * @property int|null $assigned_by_user_id
 * @property \Cake\I18n\DateTime $received_at
 * @property \Cake\I18n\DateTime|null $first_contact_at
 * @property \Cake\I18n\DateTime|null $contact_due_at
 * @property \Cake\I18n\DateTime|null $visit_due_at
 * @property \Cake\I18n\DateTime|null $close_due_at
 * @property \Cake\I18n\DateTime|null $visited_at
 * @property \Cake\I18n\DateTime|null $closed_at
 * @property int $sla_paused_minutes
 * @property bool|null $contact_sla_met
 * @property bool|null $visit_sla_met
 * @property bool|null $close_sla_met
 * @property \Cake\I18n\DateTime|null $checkin_at
 * @property string|null $checkin_latitude
 * @property string|null $checkin_longitude
 * @property int|null $checkin_accuracy_m
 * @property int|null $checkin_distance_m
 * @property \Cake\I18n\DateTime|null $checkout_at
 * @property string|null $travel_km
 * @property string|null $closure_otp_hash
 * @property \Cake\I18n\DateTime|null $closure_otp_sent_at
 * @property int $closure_otp_attempts
 * @property \Cake\I18n\DateTime|null $closure_otp_verified_at
 * @property int|null $parent_ticket_id
 * @property bool $is_repeat
 * @property int $reopened_count
 * @property \Cake\I18n\DateTime|null $company_submitted_at
 * @property \Cake\I18n\DateTime|null $company_approved_at
 * @property \Cake\I18n\DateTime|null $company_rejected_at
 * @property string|null $company_rejection_reason
 * @property \Cake\I18n\DateTime|null $charges_computed_at
 * @property \Cake\I18n\DateTime|null $charges_frozen_at
 * @property \Cake\I18n\DateTime|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property string $source
 * @property int|null $created_by_user_id
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property int|null $brand_id
 * @property int|null $symptom_id
 * @property int|null $resolution_id
 * @property string|null $company_branch_label
 * @property string|null $company_complaint_type
 * @property bool $video_proof_required
 * @property \Cake\I18n\DateTime|null $video_proof_received_at
 * @property array|null $company_payload
 *
 * @property \App\Model\Entity\Company $company
 * @property \App\Model\Entity\CompanyAgreement $company_agreement
 * @property \App\Model\Entity\RateCard $rate_card
 * @property \App\Model\Entity\ServiceCenter $service_center
 * @property \App\Model\Entity\Customer $customer
 * @property \App\Model\Entity\Product $product
 * @property \App\Model\Entity\ProductCategory $product_category
 * @property \App\Model\Entity\JobType $job_type
 * @property \App\Model\Entity\Technician $assigned_technician
 * @property \App\Model\Entity\AssignedByUser $assigned_by_user
 * @property \App\Model\Entity\ParentTicket $parent_ticket
 * @property \App\Model\Entity\CreatedByUser $created_by_user
 * @property \App\Model\Entity\Brand $brand
 * @property \App\Model\Entity\Symptom $symptom
 * @property \App\Model\Entity\Resolution $resolution
 * @property \App\Model\Entity\CashCollection[] $cash_collections
 * @property \App\Model\Entity\TechnicianPayoutLine[] $technician_payout_lines
 * @property \App\Model\Entity\TicketAttachment[] $ticket_attachments
 * @property \App\Model\Entity\TicketCharge[] $ticket_charges
 * @property \App\Model\Entity\TicketEvent[] $ticket_events
 * @property \App\Model\Entity\TicketHold[] $ticket_holds
 * @property \App\Model\Entity\TicketSpare[] $ticket_spares
 * @property \App\Model\Entity\CompanyInvoiceLine[] $company_invoice_lines
 */
class Ticket extends Entity
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
        'ticket_no' => true,
        'company_id' => true,
        'company_ticket_ref' => true,
        'company_agreement_id' => true,
        'rate_card_id' => true,
        'service_center_id' => true,
        'customer_id' => true,
        'product_id' => true,
        'product_category_id' => true,
        'model_no' => true,
        'serial_no' => true,
        'size_inch' => true,
        'purchase_date' => true,
        'warranty_scope' => true,
        'job_type_id' => true,
        'status' => true,
        'priority' => true,
        'reported_issue' => true,
        'diagnosis' => true,
        'closure_notes' => true,
        'assigned_technician_id' => true,
        'assigned_at' => true,
        'assigned_by_user_id' => true,
        'received_at' => true,
        'first_contact_at' => true,
        'contact_due_at' => true,
        'visit_due_at' => true,
        'close_due_at' => true,
        'visited_at' => true,
        'closed_at' => true,
        'sla_paused_minutes' => true,
        'contact_sla_met' => true,
        'visit_sla_met' => true,
        'close_sla_met' => true,
        'checkin_at' => true,
        'checkin_latitude' => true,
        'checkin_longitude' => true,
        'checkin_accuracy_m' => true,
        'checkin_distance_m' => true,
        'checkout_at' => true,
        'travel_km' => true,
        'closure_otp_hash' => true,
        'closure_otp_sent_at' => true,
        'closure_otp_attempts' => true,
        'closure_otp_verified_at' => true,
        'parent_ticket_id' => true,
        'is_repeat' => true,
        'reopened_count' => true,
        'company_submitted_at' => true,
        'company_approved_at' => true,
        'company_rejected_at' => true,
        'company_rejection_reason' => true,
        'charges_computed_at' => true,
        'charges_frozen_at' => true,
        'cancelled_at' => true,
        'cancellation_reason' => true,
        'source' => true,
        'created_by_user_id' => true,
        'created' => true,
        'modified' => true,
        'brand_id' => true,
        'symptom_id' => true,
        'resolution_id' => true,
        'company_branch_label' => true,
        'company_complaint_type' => true,
        'video_proof_required' => true,
        'video_proof_received_at' => true,
        'company_payload' => true,
        'company' => true,
        'company_agreement' => true,
        'rate_card' => true,
        'service_center' => true,
        'customer' => true,
        'product' => true,
        'product_category' => true,
        'job_type' => true,
        'assigned_technician' => true,
        'assigned_by_user' => true,
        'parent_ticket' => true,
        'created_by_user' => true,
        'brand' => true,
        'symptom' => true,
        'resolution' => true,
        'cash_collections' => true,
        'technician_payout_lines' => true,
        'ticket_attachments' => true,
        'ticket_charges' => true,
        'ticket_events' => true,
        'ticket_holds' => true,
        'ticket_spares' => true,
        'company_invoice_lines' => true,
    ];
}
