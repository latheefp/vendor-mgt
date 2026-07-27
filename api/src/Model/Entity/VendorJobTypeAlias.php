<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * VendorJobTypeAlias Entity
 *
 * @property int $id
 * @property int $vendor_id
 * @property int $job_type_id
 * @property string $vendor_label
 * @property string|null $warranty_scope
 * @property bool $is_active
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Vendor $vendor
 * @property \App\Model\Entity\JobType $job_type
 */
class VendorJobTypeAlias extends Entity
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
        'job_type_id' => true,
        'vendor_label' => true,
        'warranty_scope' => true,
        'is_active' => true,
        'created' => true,
        'modified' => true,
        'vendor' => true,
        'job_type' => true,
    ];
}
