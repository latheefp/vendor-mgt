<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * ServiceCenterSavingsEntry Entity
 *
 * @property int $id
 * @property int $service_center_id
 * @property string $entry_type
 * @property string $source_type
 * @property int|null $source_id
 * @property int $amount_paise
 * @property int $balance_after_paise
 * @property string $description
 * @property int|null $created_by_user_id
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\ServiceCenter $service_center
 * @property \App\Model\Entity\User $created_by_user
 */
class ServiceCenterSavingsEntry extends Entity
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
        'service_center_id' => true,
        'entry_type' => true,
        'source_type' => true,
        'source_id' => true,
        'amount_paise' => true,
        'balance_after_paise' => true,
        'description' => true,
        'created_by_user_id' => true,
        'created' => true,
        'modified' => true,
        'service_center' => true,
        'created_by_user' => true,
    ];
}
