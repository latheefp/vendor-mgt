<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * TicketHold Entity
 *
 * @property int $id
 * @property int $ticket_id
 * @property string $reason_code
 * @property string|null $notes
 * @property \Cake\I18n\DateTime $started_at
 * @property \Cake\I18n\DateTime|null $ended_at
 * @property int|null $paused_minutes
 * @property int|null $started_by_user_id
 * @property int|null $ended_by_user_id
 * @property \Cake\I18n\DateTime|null $vendor_notified_at
 * @property string|null $vendor_notification_message_id
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property int|null $hold_reason_id
 *
 * @property \App\Model\Entity\Ticket $ticket
 * @property \App\Model\Entity\StartedByUser $started_by_user
 * @property \App\Model\Entity\EndedByUser $ended_by_user
 * @property \App\Model\Entity\HoldReason $hold_reason
 */
class TicketHold extends Entity
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
        'ticket_id' => true,
        'reason_code' => true,
        'notes' => true,
        'started_at' => true,
        'ended_at' => true,
        'paused_minutes' => true,
        'started_by_user_id' => true,
        'ended_by_user_id' => true,
        'vendor_notified_at' => true,
        'vendor_notification_message_id' => true,
        'created' => true,
        'modified' => true,
        'hold_reason_id' => true,
        'ticket' => true,
        'started_by_user' => true,
        'ended_by_user' => true,
        'hold_reason' => true,
    ];
}
