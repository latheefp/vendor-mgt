<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;

/**
 * Roles, service centres and users.
 *
 * A service centre is not decoration: the agreement reimburses travel
 * "beyond 15 kilometres from the designated service center", so every
 * ticket must know which centre it was dispatched from before any
 * travel money can be computed.
 */
class CreateIdentity extends AppMigration
{
    public function up(): void
    {
        $roles = $this->newTable('roles');
        $roles
            ->addColumn('code', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('permissions', 'json', ['null' => true])
            ->addColumn('is_system', 'boolean', ['null' => false, 'default' => false]);
        $this->timestamps($roles)
            ->addIndex(['code'], ['unique' => true, 'name' => 'uq_roles_code'])
            ->create();

        $centers = $this->newTable('service_centers');
        $centers
            ->addColumn('code', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('contact_person', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('phone', 'string', ['limit' => 24, 'null' => true])
            ->addColumn('email', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('address_line1', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('address_line2', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('city', 'string', ['limit' => 96, 'null' => true])
            ->addColumn('district', 'string', ['limit' => 96, 'null' => true])
            ->addColumn('state', 'string', ['limit' => 96, 'null' => true, 'default' => 'Kerala'])
            ->addColumn('pincode', 'string', ['limit' => 12, 'null' => true])
            // Travel beyond the free radius is measured from this point.
            ->addColumn('latitude', 'decimal', ['precision' => 10, 'scale' => 7, 'null' => true])
            ->addColumn('longitude', 'decimal', ['precision' => 10, 'scale' => 7, 'null' => true])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true]);
        $this->timestamps($centers)
            ->addIndex(['code'], ['unique' => true, 'name' => 'uq_service_centers_code'])
            ->create();

        $users = $this->newTable('users');
        $users
            ->addColumn('role_id', 'biginteger', self::KEY)
            ->addColumn('service_center_id', 'biginteger', self::NULLABLE_KEY)
            ->addColumn('name', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('email', 'string', ['limit' => 190, 'null' => false])
            ->addColumn('phone', 'string', ['limit' => 24, 'null' => true])
            ->addColumn('password', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('must_change_password', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('last_login_at', 'datetime', ['null' => true])
            ->addColumn('last_login_ip', 'string', ['limit' => 45, 'null' => true])
            // Field staff share and lose phones; lockout is cheap insurance.
            ->addColumn('failed_login_count', 'integer', ['null' => false, 'default' => 0, 'signed' => false])
            ->addColumn('locked_until', 'datetime', ['null' => true]);
        $this->timestamps($users)
            ->addIndex(['email'], ['unique' => true, 'name' => 'uq_users_email'])
            ->addIndex(['role_id'], ['name' => 'idx_users_role'])
            ->addIndex(['service_center_id'], ['name' => 'idx_users_service_center'])
            ->addForeignKey('role_id', 'roles', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_users_role',
            ])
            ->addForeignKey('service_center_id', 'service_centers', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_users_service_center',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('users')->drop()->save();
        $this->table('service_centers')->drop()->save();
        $this->table('roles')->drop()->save();
    }
}
