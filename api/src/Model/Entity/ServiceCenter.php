<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * ServiceCenter Entity
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $contact_person
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $district
 * @property string|null $state
 * @property string|null $pincode
 * @property string|null $latitude
 * @property string|null $longitude
 * @property bool $is_active
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Technician[] $technicians
 * @property \App\Model\Entity\Ticket[] $tickets
 * @property \App\Model\Entity\User[] $users
 */
class ServiceCenter extends Entity
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
        'code' => true,
        'name' => true,
        'contact_person' => true,
        'phone' => true,
        'email' => true,
        'address_line1' => true,
        'address_line2' => true,
        'city' => true,
        'district' => true,
        'state' => true,
        'pincode' => true,
        'latitude' => true,
        'longitude' => true,
        'is_active' => true,
        'created' => true,
        'modified' => true,
        'technicians' => true,
        'tickets' => true,
        'users' => true,
    ];
}
