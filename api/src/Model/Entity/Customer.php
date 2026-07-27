<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Customer Entity
 *
 * @property int $id
 * @property string $name
 * @property string $phone
 * @property string|null $alt_phone
 * @property string|null $email
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $landmark
 * @property string|null $city
 * @property string|null $pincode
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string $source
 * @property string|null $notes
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property int|null $state_id
 * @property int|null $district_id
 * @property string|null $place
 *
 * @property \App\Model\Entity\District $district
 * @property \App\Model\Entity\State $state
 * @property \App\Model\Entity\Ticket[] $tickets
 */
class Customer extends Entity
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
        'name' => true,
        'phone' => true,
        'alt_phone' => true,
        'email' => true,
        'address_line1' => true,
        'address_line2' => true,
        'landmark' => true,
        'city' => true,
        'district' => true,
        'state' => true,
        'pincode' => true,
        'latitude' => true,
        'longitude' => true,
        'source' => true,
        'notes' => true,
        'created' => true,
        'modified' => true,
        'state_id' => true,
        'district_id' => true,
        'place' => true,
        'tickets' => true,
    ];
}
