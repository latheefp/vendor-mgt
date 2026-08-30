<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * CompanySetting Entity
 *
 * One company's answer to one setting, stored as text and cast on read by
 * the definition in SettingCatalog. A row with a null company_id is the
 * platform default rather than any company's choice.
 *
 * @property int $id
 * @property int|null $company_id
 * @property string $setting_key
 * @property string $value_type
 * @property string|null $value
 * @property string|null $label
 * @property string|null $description
 * @property bool $is_editable
 * @property bool $is_active
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Company $company
 */
class CompanySetting extends Entity
{
    /**
     * `company_key` is a generated column and is absent on purpose — the
     * database computes it from company_id, and letting it be mass assigned
     * would produce a write error rather than a useful override.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'company_id' => true,
        'setting_key' => true,
        'value_type' => true,
        'value' => true,
        'label' => true,
        'description' => true,
        'is_editable' => true,
        'is_active' => true,
        'created' => true,
        'modified' => true,
        'company' => true,
    ];
}
