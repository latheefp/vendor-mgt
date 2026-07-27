<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * District Entity
 *
 * @property int $id
 * @property int $state_id
 * @property string $code
 * @property string $name
 * @property array|null $aliases
 * @property bool $is_active
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\State $state
 * @property \App\Model\Entity\Customer[] $customers
 */
class District extends Entity
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
        'state_id' => true,
        'code' => true,
        'name' => true,
        'aliases' => true,
        'is_active' => true,
        'created' => true,
        'modified' => true,
        'state' => true,
        'customers' => true,
    ];
}
