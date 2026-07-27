<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * TicketEvent Entity
 *
 * @property int $id
 * @property int $ticket_id
 * @property string $event_type
 * @property string|null $from_status
 * @property string|null $to_status
 * @property int|null $actor_user_id
 * @property string|null $actor_role
 * @property string|null $description
 * @property array|null $payload
 * @property \Cake\I18n\DateTime $occurred_at
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Cake\I18n\DateTime $created
 *
 * @property \App\Model\Entity\Ticket $ticket
 * @property \App\Model\Entity\ActorUser $actor_user
 */
class TicketEvent extends Entity
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
        'event_type' => true,
        'from_status' => true,
        'to_status' => true,
        'actor_user_id' => true,
        'actor_role' => true,
        'description' => true,
        'payload' => true,
        'occurred_at' => true,
        'latitude' => true,
        'longitude' => true,
        'ip_address' => true,
        'user_agent' => true,
        'created' => true,
        'ticket' => true,
        'actor_user' => true,
    ];
}
