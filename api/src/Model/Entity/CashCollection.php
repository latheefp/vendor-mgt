<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * CashCollection Entity
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $technician_id
 * @property int|null $collected_by_user_id
 * @property int $amount_paise
 * @property string $method
 * @property string|null $reference
 * @property string|null $receipt_no
 * @property \Cake\I18n\DateTime $collected_at
 * @property \Cake\I18n\DateTime|null $deposited_at
 * @property string|null $deposit_reference
 * @property int|null $deposited_paise
 * @property int $variance_paise
 * @property \Cake\I18n\DateTime|null $verified_at
 * @property int|null $verified_by_user_id
 * @property string $status
 * @property string|null $notes
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Ticket $ticket
 * @property \App\Model\Entity\Technician $technician
 * @property \App\Model\Entity\CollectedByUser $collected_by_user
 * @property \App\Model\Entity\VerifiedByUser $verified_by_user
 */
class CashCollection extends Entity
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
        'technician_id' => true,
        'collected_by_user_id' => true,
        'amount_paise' => true,
        'method' => true,
        'reference' => true,
        'receipt_no' => true,
        'collected_at' => true,
        'deposited_at' => true,
        'deposit_reference' => true,
        'deposited_paise' => true,
        'variance_paise' => true,
        'verified_at' => true,
        'verified_by_user_id' => true,
        'status' => true,
        'notes' => true,
        'created' => true,
        'modified' => true,
        'ticket' => true,
        'technician' => true,
        'collected_by_user' => true,
        'verified_by_user' => true,
    ];
}
