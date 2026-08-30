<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * CompanyJobTypeAlias Entity
 *
 * @property int $id
 * @property int $company_id
 * @property int $job_type_id
 * @property string $company_label
 * @property string|null $warranty_scope
 * @property bool $is_active
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Company $company
 * @property \App\Model\Entity\JobType $job_type
 */
class CompanyJobTypeAlias extends Entity
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
        'job_type_id' => true,
        'company_label' => true,
        'warranty_scope' => true,
        'is_active' => true,
        'created' => true,
        'modified' => true,
        'company' => true,
        'job_type' => true,
    ];
}
