<?php
declare(strict_types=1);

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Migrations\BaseSeed;

/**
 * Development sign-ins, one per role, plus the technician profile that
 * the field app needs in order to have a job list at all.
 *
 * Every account is created with `must_change_password = 1`. These are
 * demo credentials with a shared, guessable password — the flag is what
 * stops one quietly surviving into production.
 *
 *   bin/cake seeds run UserSeed   (after MasterListSeed)
 */
class UserSeed extends BaseSeed
{
    /** Development only. Every account is flagged to force a change. */
    private const DEV_PASSWORD = 'Password123!';

    public function run(): void
    {
        $now = date('Y-m-d H:i:s');
        $roles = $this->lookup('roles');
        $centers = $this->lookup('service_centers');

        // Hashed here rather than assigned raw: the seed writes straight to
        // the table and so bypasses the entity setter that would normally
        // do it. A plaintext password must never reach the database.
        $hasher = new DefaultPasswordHasher();
        $password = $hasher->hash(self::DEV_PASSWORD);

        $users = [
            // email, name, role, service centre, phone
            ['admin@grandservice.in', 'System Administrator', 'admin', 'THA', '9447500001'],
            ['desk@grandservice.in', 'Service Desk', 'desk', 'THA', '9447500002'],
            ['accounts@grandservice.in', 'Accounts', 'accounts', 'THA', '9447500003'],
            ['rajeev@grandservice.in', 'Rajeev Kumar', 'technician', 'THA', '9447500004'],
            ['saneesh@grandservice.in', 'Saneesh P', 'technician', 'THA', '9447500005'],
        ];

        $rows = [];
        foreach ($users as [$email, $name, $role, $center, $phone]) {
            $rows[] = [
                'role_id' => $roles[$role],
                'service_center_id' => $centers[$center],
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password' => $password,
                'is_active' => 1,
                'must_change_password' => 1,
                'failed_login_count' => 0,
                'created' => $now,
                'modified' => $now,
            ];
        }

        $this->table('users')->insert($rows)->save();

        $this->seedTechnicians($centers, $now);
    }

    /**
     * A login is not enough to work a job.
     *
     * Tickets are assigned to a `technician`, not to a `user`, because a
     * technician has things a login does not: a skill set that gates which
     * job types the desk may assign, a payout rate, and bank details. The
     * two are linked, and the field app needs both.
     */
    private function seedTechnicians(array $centers, string $now): void
    {
        $userIds = [];
        foreach ($this->fetchAll('SELECT id, email FROM users') as $row) {
            $userIds[$row['email']] = (int)$row['id'];
        }

        $this->table('technicians')->insert([
            [
                'user_id' => $userIds['rajeev@grandservice.in'],
                'service_center_id' => $centers['THA'],
                'code' => 'TECH-001',
                'name' => 'Rajeev Kumar',
                'phone' => '9447500004',
                'employment_type' => 'contractor',
                'joined_on' => date('Y-m-d'),
                'district' => 'Kozhikode',
                // Panel work is not something every technician can do, so
                // the desk cannot assign it to someone without the skill.
                'skills' => json_encode(['installation', 'demo_inspection', 'service', 'panel_backlight']),
                'max_open_tickets' => 10,
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ],
            [
                'user_id' => $userIds['saneesh@grandservice.in'],
                'service_center_id' => $centers['THA'],
                'code' => 'TECH-002',
                'name' => 'Saneesh P',
                'phone' => '9447500005',
                'employment_type' => 'employee',
                'joined_on' => date('Y-m-d'),
                'district' => 'Kozhikode',
                'skills' => json_encode(['installation', 'demo_inspection', 'service']),
                'max_open_tickets' => 8,
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ],
        ])->save();

        $this->seedTechnicianRates($now);
    }

    /**
     * How each technician is paid.
     *
     * Rajeev is a contractor on a flat per-job rate who keeps his full SLA
     * incentive and carries half of any SLA deduction he causes. Saneesh is
     * salaried, so he earns no per-job base — but he still earns incentives,
     * because otherwise nothing rewards him for closing inside 24 hours.
     */
    private function seedTechnicianRates(string $now): void
    {
        $techIds = [];
        foreach ($this->fetchAll('SELECT id, code FROM technicians') as $row) {
            $techIds[$row['code']] = (int)$row['id'];
        }

        $this->table('technician_rates')->insert([
            [
                'technician_id' => $techIds['TECH-001'],
                'effective_from' => date('Y-m-d'),
                'model' => 'flat_per_job',
                'flat_amount_paise' => 20_000,           // Rs.200 per job
                'travel_free_km' => '0.00',
                'travel_rate_per_km_paise' => 200,       // Rs.2/km, vs Rs.3 from the company
                'bonus_share_pct' => '100.00',
                'penalty_recovery_pct' => '50.00',
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ],
            [
                'technician_id' => $techIds['TECH-002'],
                'effective_from' => date('Y-m-d'),
                'model' => 'salaried',
                'monthly_salary_paise' => 1_800_000,     // Rs.18,000 a month
                'travel_free_km' => '0.00',
                'travel_rate_per_km_paise' => 200,
                'bonus_share_pct' => '100.00',
                'penalty_recovery_pct' => '0.00',
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ],
        ])->save();
    }

    /**
     * @return array<string, int>
     */
    private function lookup(string $table): array
    {
        $map = [];
        foreach ($this->fetchAll(sprintf('SELECT id, code FROM %s', $table)) as $row) {
            $map[$row['code']] = (int)$row['id'];
        }

        return $map;
    }
}
