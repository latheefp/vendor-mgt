<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Product Entity
 *
 * @property int $id
 * @property int $company_id
 * @property int $product_category_id
 * @property string $model_no
 * @property string|null $name
 * @property string|null $size_inch
 * @property int|null $warranty_months
 * @property int|null $panel_warranty_months
 * @property bool $is_active
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property int|null $brand_id
 *
 * @property \App\Model\Entity\Company $company
 * @property \App\Model\Entity\ProductCategory $product_category
 * @property \App\Model\Entity\Brand $brand
 * @property \App\Model\Entity\Ticket[] $tickets
 */
class Product extends Entity
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
        'model_no' => true,
        'name' => true,
        'size_inch' => true,
        'warranty_months' => true,
        'panel_warranty_months' => true,
        'is_active' => true,
        'created' => true,
        'modified' => true,
        'brand_id' => true,
        'company' => true,
        'product_category' => true,
        'brand' => true,
        'tickets' => true,
    ];
}
