<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Technician Entity
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $service_center_id
 * @property string $code
 * @property string $name
 * @property string $phone
 * @property string|null $alt_phone
 * @property string|null $email
 * @property string $employment_type
 * @property \Cake\I18n\Date|null $joined_on
 * @property \Cake\I18n\Date|null $exited_on
 * @property string|null $address_line1
 * @property string|null $city
 * @property string|null $district
 * @property string|null $pincode
 * @property array|null $skills
 * @property string|null $id_proof_type
 * @property string|null $id_proof_number
 * @property string|null $bank_account_name
 * @property string|null $bank_account_number
 * @property string|null $bank_ifsc
 * @property string|null $upi_id
 * @property int $max_open_tickets
 * @property bool $is_active
 * @property string|null $notes
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\User $user
 * @property \App\Model\Entity\ServiceCenter $service_center
 * @property \App\Model\Entity\CashCollection[] $cash_collections
 * @property \App\Model\Entity\TechnicianPayout[] $technician_payouts
 * @property \App\Model\Entity\TechnicianRate[] $technician_rates
 */
class Technician extends Entity
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
        'user_id' => true,
        'service_center_id' => true,
        'code' => true,
        'name' => true,
        'phone' => true,
        'alt_phone' => true,
        'email' => true,
        'employment_type' => true,
        'joined_on' => true,
        'exited_on' => true,
        'address_line1' => true,
        'city' => true,
        'district' => true,
        'pincode' => true,
        'skills' => true,
        'id_proof_type' => true,
        'id_proof_number' => true,
        'bank_account_name' => true,
        'bank_account_number' => true,
        'bank_ifsc' => true,
        'upi_id' => true,
        'max_open_tickets' => true,
        'is_active' => true,
        'notes' => true,
        'created' => true,
        'modified' => true,
        'user' => true,
        'service_center' => true,
        'cash_collections' => true,
        'technician_payouts' => true,
        'technician_rates' => true,
    ];
}
