<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;
use Authentication\PasswordHasher\DefaultPasswordHasher;

/**
 * The `admin` role and a first admin account, so a freshly migrated
 * database has someone who can sign in at all.
 *
 * This has to be a migration rather than a seed: seeds are not tracked,
 * so re-running one against a database that already has these rows
 * fails on the unique email/role code. Migrations run once, tracked in
 * phinxlog, which is also what makes `bin/cake migrations migrate` safe
 * to run unconditionally on every container boot (see docker/entrypoint.sh).
 *
 * The password is a placeholder the account is forced to change on
 * first login (`must_change_password`), not a credential meant to
 * survive — same convention as config/Seeds/UserSeed.php.
 */
class SeedAdminAuth extends AppMigration
{
    private const INITIAL_PASSWORD = 'Password123!';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $role = $this->fetchRow("SELECT id FROM roles WHERE code = 'admin'");
        if ($role) {
            $roleId = (int)$role['id'];
        } else {
            $this->table('roles')->insert([
                'code' => 'admin',
                'name' => 'Administrator',
                'description' => 'Full access including rate cards and SLA rules',
                'permissions' => json_encode(['*']),
                'is_system' => 1,
                'created' => $now,
                'modified' => $now,
            ])->save();
            $roleId = (int)$this->fetchRow("SELECT id FROM roles WHERE code = 'admin'")['id'];
        }

        $existing = $this->fetchRow("SELECT id FROM users WHERE email = 'admin@grandservice.in'");
        if ($existing) {
            return;
        }

        $this->table('users')->insert([
            'role_id' => $roleId,
            'service_center_id' => null,
            'name' => 'System Administrator',
            'email' => 'admin@grandservice.in',
            'phone' => null,
            'password' => (new DefaultPasswordHasher())->hash(self::INITIAL_PASSWORD),
            'is_active' => 1,
            'must_change_password' => 1,
            'failed_login_count' => 0,
            'created' => $now,
            'modified' => $now,
        ])->save();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM users WHERE email = 'admin@grandservice.in'");
        // The admin role itself is left in place — other rows may reference it.
    }
}
