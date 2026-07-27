<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * RateCardItem Entity
 *
 * @property int $id
 * @property int $rate_card_id
 * @property int $job_type_id
 * @property int|null $product_category_id
 * @property string $warranty_scope
 * @property string|null $size_min_inch
 * @property string|null $size_max_inch
 * @property int $amount_paise
 * @property string $payer
 * @property string $label
 * @property int $priority
 * @property bool $is_active
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\RateCard $rate_card
 * @property \App\Model\Entity\JobType $job_type
 * @property \App\Model\Entity\ProductCategory $product_category
 * @property \App\Model\Entity\TicketCharge[] $ticket_charges
 */
class RateCardItem extends Entity
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
        'job_type_id' => true,
        'product_category_id' => true,
        'warranty_scope' => true,
        'size_min_inch' => true,
        'size_max_inch' => true,
        'amount_paise' => true,
        'payer' => true,
        'label' => true,
        'priority' => true,
        'is_active' => true,
        'created' => true,
        'modified' => true,
        'rate_card' => true,
        'job_type' => true,
        'product_category' => true,
        'ticket_charges' => true,
    ];
}
