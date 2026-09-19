<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * AppSetting Entity
 *
 * @property int $id
 * @property string|null $logo_base64
 * @property string|null $favicon_base64
 * @property string $timezone
 * @property string $date_format
 * @property string $time_format
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class AppSetting extends Entity
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
        'logo_base64' => true,
        'favicon_base64' => true,
        'timezone' => true,
        'date_format' => true,
        'time_format' => true,
        'created' => true,
        'modified' => true,
    ];
}
