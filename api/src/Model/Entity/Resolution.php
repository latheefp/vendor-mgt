<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Resolution Entity
 *
 * @property int $id
 * @property int|null $company_id
 * @property string|null $override_note
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $requires_spare
 * @property bool $is_billable
 * @property int $sort_order
 * @property bool $is_active
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Ticket[] $tickets
 */
class Resolution extends Entity
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
        'company_id' => true,
        'override_note' => true,
        'code' => true,
        'name' => true,
        'description' => true,
        'requires_spare' => true,
        'is_billable' => true,
        'sort_order' => true,
        'is_active' => true,
        'created' => true,
        'modified' => true,
        'tickets' => true,
    ];
}
