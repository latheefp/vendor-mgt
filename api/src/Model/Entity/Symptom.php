<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Symptom Entity
 *
 * @property int $id
 * @property int|null $vendor_id
 * @property string|null $override_note
 * @property int|null $product_category_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property array|null $aliases
 * @property bool $is_panel_related
 * @property bool $requires_video_proof
 * @property int $sort_order
 * @property bool $is_active
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\ProductCategory $product_category
 * @property \App\Model\Entity\Ticket[] $tickets
 */
class Symptom extends Entity
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
        'override_note' => true,
        'product_category_id' => true,
        'code' => true,
        'name' => true,
        'description' => true,
        'aliases' => true,
        'is_panel_related' => true,
        'requires_video_proof' => true,
        'sort_order' => true,
        'is_active' => true,
        'created' => true,
        'modified' => true,
        'product_category' => true,
        'tickets' => true,
    ];
}
