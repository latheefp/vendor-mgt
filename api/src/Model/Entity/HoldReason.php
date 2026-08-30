<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * HoldReason Entity
 *
 * @property int $id
 * @property int|null $company_id
 * @property string|null $override_note
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $pauses_sla
 * @property bool $requires_company_notice
 * @property int $sort_order
 * @property bool $is_active
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\TicketHold[] $ticket_holds
 */
class HoldReason extends Entity
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
        'pauses_sla' => true,
        'requires_company_notice' => true,
        'sort_order' => true,
        'is_active' => true,
        'created' => true,
        'modified' => true,
        'ticket_holds' => true,
    ];
}
