<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * TechnicianRate Entity
 *
 * @property int $id
 * @property int $technician_id
 * @property \Cake\I18n\Date $effective_from
 * @property \Cake\I18n\Date|null $effective_to
 * @property string $model
 * @property int|null $flat_amount_paise
 * @property string|null $pct_of_vendor
 * @property int|null $monthly_salary_paise
 * @property string $travel_free_km
 * @property int $travel_rate_per_km_paise
 * @property string $bonus_share_pct
 * @property string $penalty_recovery_pct
 * @property array|null $applies_to_job_types
 * @property bool $is_active
 * @property string|null $notes
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Technician $technician
 * @property \App\Model\Entity\TicketCharge[] $ticket_charges
 */
class TechnicianRate extends Entity
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
        'effective_from' => true,
        'effective_to' => true,
        'model' => true,
        'flat_amount_paise' => true,
        'pct_of_vendor' => true,
        'monthly_salary_paise' => true,
        'travel_free_km' => true,
        'travel_rate_per_km_paise' => true,
        'bonus_share_pct' => true,
        'penalty_recovery_pct' => true,
        'applies_to_job_types' => true,
        'is_active' => true,
        'notes' => true,
        'created' => true,
        'modified' => true,
        'technician' => true,
        'ticket_charges' => true,
    ];
}
