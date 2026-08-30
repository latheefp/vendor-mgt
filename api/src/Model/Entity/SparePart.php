<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * SparePart Entity
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $product_category_id
 * @property string $part_no
 * @property string $name
 * @property string|null $description
 * @property int $cost_paise
 * @property int|null $mrp_paise
 * @property bool $is_serialized
 * @property int $reorder_level
 * @property bool $is_active
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Company $company
 * @property \App\Model\Entity\ProductCategory $product_category
 * @property \App\Model\Entity\TicketSpare[] $ticket_spares
 */
class SparePart extends Entity
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
        'product_category_id' => true,
        'part_no' => true,
        'name' => true,
        'description' => true,
        'cost_paise' => true,
        'mrp_paise' => true,
        'is_serialized' => true,
        'reorder_level' => true,
        'is_active' => true,
        'created' => true,
        'modified' => true,
        'company' => true,
        'product_category' => true,
        'ticket_spares' => true,
    ];
}
