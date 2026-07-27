<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * TechnicianPayout Entity
 *
 * @property int $id
 * @property int $technician_id
 * @property string $payout_no
 * @property \Cake\I18n\Date $period_start
 * @property \Cake\I18n\Date $period_end
 * @property string $status
 * @property int $gross_paise
 * @property int $bonus_paise
 * @property int $travel_paise
 * @property int $penalty_recovery_paise
 * @property int $advance_recovery_paise
 * @property int $deductions_paise
 * @property int $net_paise
 * @property int $ticket_count
 * @property \Cake\I18n\DateTime|null $approved_at
 * @property int|null $approved_by_user_id
 * @property \Cake\I18n\DateTime|null $paid_at
 * @property string|null $payment_method
 * @property string|null $payment_reference
 * @property string|null $pdf_path
 * @property int|null $created_by_user_id
 * @property string|null $notes
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Technician $technician
 * @property \App\Model\Entity\ApprovedByUser $approved_by_user
 * @property \App\Model\Entity\CreatedByUser $created_by_user
 * @property \App\Model\Entity\TechnicianPayoutLine[] $technician_payout_lines
 */
class TechnicianPayout extends Entity
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
        'technician_id' => true,
        'payout_no' => true,
        'period_start' => true,
        'period_end' => true,
        'status' => true,
        'gross_paise' => true,
        'bonus_paise' => true,
        'travel_paise' => true,
        'penalty_recovery_paise' => true,
        'advance_recovery_paise' => true,
        'deductions_paise' => true,
        'net_paise' => true,
        'ticket_count' => true,
        'approved_at' => true,
        'approved_by_user_id' => true,
        'paid_at' => true,
        'payment_method' => true,
        'payment_reference' => true,
        'pdf_path' => true,
        'created_by_user_id' => true,
        'notes' => true,
        'created' => true,
        'modified' => true,
        'technician' => true,
        'approved_by_user' => true,
        'created_by_user' => true,
        'technician_payout_lines' => true,
    ];
}
