<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * TicketAttachment Entity
 *
 * @property int $id
 * @property int $ticket_id
 * @property string $kind
 * @property string $storage_disk
 * @property string $storage_path
 * @property string|null $original_name
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string|null $sha256
 * @property \Cake\I18n\DateTime|null $exif_taken_at
 * @property string|null $captured_latitude
 * @property string|null $captured_longitude
 * @property int|null $uploaded_by_user_id
 * @property \Cake\I18n\DateTime $uploaded_at
 * @property bool $is_verified
 * @property string|null $verification_note
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Ticket $ticket
 * @property \App\Model\Entity\UploadedByUser $uploaded_by_user
 */
class TicketAttachment extends Entity
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
        'kind' => true,
        'storage_disk' => true,
        'storage_path' => true,
        'original_name' => true,
        'mime_type' => true,
        'size_bytes' => true,
        'width' => true,
        'height' => true,
        'sha256' => true,
        'exif_taken_at' => true,
        'captured_latitude' => true,
        'captured_longitude' => true,
        'uploaded_by_user_id' => true,
        'uploaded_at' => true,
        'is_verified' => true,
        'verification_note' => true,
        'created' => true,
        'modified' => true,
        'ticket' => true,
        'uploaded_by_user' => true,
    ];
}
