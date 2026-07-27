<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\ORM\Entity;

/**
 * User Entity
 *
 * @property int $id
 * @property int $role_id
 * @property int|null $service_center_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string $password
 * @property bool $is_active
 * @property bool $must_change_password
 * @property \Cake\I18n\DateTime|null $last_login_at
 * @property string|null $last_login_ip
 * @property int $failed_login_count
 * @property \Cake\I18n\DateTime|null $locked_until
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 *
 * @property \App\Model\Entity\Role $role
 * @property \App\Model\Entity\ServiceCenter $service_center
 * @property \App\Model\Entity\Technician $technician
 */
class User extends Entity
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
        'role_id' => true,
        'service_center_id' => true,
        'name' => true,
        'email' => true,
        'phone' => true,
        'password' => true,
        'is_active' => true,
        'must_change_password' => true,
        'last_login_at' => true,
        'last_login_ip' => true,
        'failed_login_count' => true,
        'locked_until' => true,
        'created' => true,
        'modified' => true,
        'role' => true,
        'service_center' => true,
        'technician' => true,
    ];

    /**
     * Fields that are excluded from JSON versions of the entity.
     *
     * @var array<string>
     */
    protected array $_hidden = [
        'password',
    ];

    /**
     * Hash the password on the way in.
     *
     * Placed on the entity so it is impossible to store a plaintext password
     * by any route — a seed, a console command, an import, or a controller
     * someone writes next year. There is no code path that sets `password`
     * and skips this.
     */
    protected function _setPassword(?string $password): ?string
    {
        if ($password === null || $password === '') {
            return null;
        }

        return (new DefaultPasswordHasher())->hash($password);
    }
}
