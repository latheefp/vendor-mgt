<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * SlaRule Entity
 *
 * @property int $id
 * @property int $rate_card_id
 * @property string $code
 * @property string $label
 * @property string $kind
 * @property string $metric
 * @property string $comparator
 * @property string|null $threshold_from_hours
 * @property string|null $threshold_to_hours
 * @property int $amount_paise
 * @property array|null $applies_to_job_types
 * @property string|null $warranty_scope
 * @property int $priority
 * @property bool $is_stackable
 * @property bool $is_active
 * @property string|null $notes
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\RateCard $rate_card
 * @property \App\Model\Entity\TicketCharge[] $ticket_charges
 */
class SlaRule extends Entity
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
        'rate_card_id' => true,
        'code' => true,
        'label' => true,
        'kind' => true,
        'metric' => true,
        'comparator' => true,
        'threshold_from_hours' => true,
        'threshold_to_hours' => true,
        'amount_paise' => true,
        'applies_to_job_types' => true,
        'warranty_scope' => true,
        'priority' => true,
        'is_stackable' => true,
        'is_active' => true,
        'notes' => true,
        'created' => true,
        'modified' => true,
        'rate_card' => true,
        'ticket_charges' => true,
    ];
}
