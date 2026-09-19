<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;

/**
 * The rest of the platform's standard vocabulary that
 * `SeedStandardMasterData` left out: desk/technician/accounts roles, our
 * own service centres, coded symptoms, resolution reasons and hold
 * reasons.
 *
 * `config/Seeds/MasterListSeed.php` builds the same rows by hand, but
 * seeds are not run automatically (only `bin/cake migrations migrate`
 * runs on every container boot — see docker/entrypoint.sh), so an
 * environment where nobody remembers to run the seed ends up with an
 * empty "How was it resolved?" dropdown and no hold reasons — exactly
 * what happened on an environment that only ever got `DianoraSeed`
 * (whose resolution/hold-reason overrides silently no-op when the shared
 * baseline row they shadow does not exist yet).
 *
 * `insertOrSkip()` throughout, same as `SeedStandardMasterData`, so this
 * is also safe to run against a database where someone already ran the
 * old seed files by hand — the unique code indexes make the second
 * write a no-op rather than a duplicate-key error.
 */
class SeedOperationalMasterLists extends AppMigration
{
    /**
     * @var array<int, array{0: string, 1: string, 2: ?string, 3: array<int, string>}>
     */
    private const ROLES = [
        // code, name, description, permissions
        ['desk', 'Service desk', 'Intake, assignment, holds and company correspondence', [
            'tickets.*', 'customers.*', 'holds.*', 'technicians.view', 'reports.view',
        ]],
        ['technician', 'Field technician', null, [
            'tickets.own.view', 'tickets.own.checkin', 'tickets.own.close',
            'attachments.upload', 'collections.create',
        ]],
        ['accounts', 'Accounts', 'Invoicing, payouts, cash reconciliation, credit exposure', [
            'invoices.*', 'payouts.*', 'collections.*', 'reports.*', 'tickets.view',
        ]],
    ];

    /**
     * @var array<int, array<string, mixed>>
     */
    private const SERVICE_CENTERS = [
        [
            'code' => 'THA',
            'name' => 'GRAND SERVICE THARASSERY',
            'city' => 'Thamarassery',
            'district' => 'Kozhikode',
            'state' => 'Kerala',
            'pincode' => '673573',
            'latitude' => '11.4090000',
            'longitude' => '75.9370000',
        ],
        [
            'code' => 'ALP',
            'name' => 'GRAND SERVICE ALAPPUZHA',
            'city' => 'Alappuzha',
            'district' => 'Alappuzha',
            'state' => 'Kerala',
            'pincode' => '688001',
            'latitude' => '9.4980000',
            'longitude' => '76.3388000',
        ],
    ];

    /**
     * code, name, panel related, needs video, aliases — same list as
     * MasterListSeed, all under the `led_tv` category.
     *
     * @var array<int, array{0: string, 1: string, 2: bool, 3: bool, 4: array<int, string>}>
     */
    private const SYMPTOMS = [
        ['no_power', 'No power / dead set', false, false, ['DEAD', 'NOT SWITCHING ON', 'NO POWER']],
        ['display_line', 'Display line on screen', true, true, ['DISPLAY LINE', 'LINE ON SCREEN', 'VERTICAL LINE']],
        ['no_display_sound_ok', 'No display, sound present', true, true, ['NO DISPLAY', 'BLANK SCREEN']],
        ['half_screen', 'Half screen / partial display', true, true, ['HALF DISPLAY', 'HALF SCREEN']],
        ['backlight_dim', 'Dim or uneven backlight', true, true, ['DIM DISPLAY', 'DARK SCREEN']],
        ['panel_broken', 'Physically broken panel', true, true, ['BROKEN', 'PANEL CRACK', 'SCREEN BROKEN']],
        ['no_sound', 'No sound, picture normal', false, false, ['NO AUDIO', 'SOUND PROBLEM']],
        ['remote_fault', 'Remote not working', false, false, ['REMOTE COMPLAINT']],
        ['smart_features', 'Smart / app / network fault', false, false, ['WIFI ISSUE', 'APP NOT WORKING']],
        ['auto_restart', 'Auto restart or shutdown', false, true, ['RESTARTING', 'AUTO OFF']],
        ['picture_quality', 'Picture quality complaint', false, true, ['PICTURE PROBLEM', 'COLOUR ISSUE']],
        ['installation_only', 'Installation request', false, false, ['INSTALLATION', 'FIXING']],
        ['demo_request', 'Demo or usage guidance', false, false, ['DEMO']],
        ['other', 'Other / to be diagnosed on site', false, false, []],
    ];

    /**
     * code, name, requires spare, is billable — the shared baseline every
     * company starts from (company_id NULL). A company can shadow any of
     * these with its own row of the same code, same as DianoraSeed does
     * for `no_fault_found`.
     *
     * @var array<int, array{0: string, 1: string, 2: bool, 3: bool}>
     */
    private const RESOLUTIONS = [
        ['repaired_no_spare', 'Repaired without spare', false, true],
        ['spare_replaced', 'Spare part replaced', true, true],
        ['panel_replaced', 'Panel / open cell replaced', true, true],
        ['backlight_replaced', 'Backlight replaced', true, true],
        ['software_update', 'Software update / reset', false, true],
        ['installed', 'Installed and demonstrated', false, true],
        ['demo_given', 'Demo given', false, true],
        ['unit_exchanged', 'Unit exchanged', false, true],
        ['no_fault_found', 'No fault found', false, false],
        ['customer_misuse', 'Customer-induced damage, not covered', false, false],
        ['customer_cancelled', 'Cancelled by customer', false, false],
        ['unreachable', 'Customer unreachable after repeated attempts', false, false],
        ['estimate_declined', 'Customer declined the estimate', false, false],
        ['referred_workshop', 'Referred to workshop', false, true],
    ];

    /**
     * code, name, pauses SLA, needs company notice — same shared-baseline
     * shape as RESOLUTIONS.
     *
     * @var array<int, array{0: string, 1: string, 2: bool, 3: bool}>
     */
    private const HOLD_REASONS = [
        ['customer_unavailable', 'Customer unavailable', true, true],
        ['customer_postponed', 'Customer asked to postpone', true, true],
        ['address_wrong', 'Address or contact number incorrect', true, true],
        ['access_denied', 'No access to the site', true, true],
        ['video_proof_awaited', 'Awaiting symptom video from customer', true, true],
        ['spare_awaited', 'Awaiting spare part from company', true, true],
        ['company_approval_pending', 'Awaiting company approval or estimate', true, true],
        ['estimate_with_customer', 'Estimate with customer for approval', true, true],
        // Ours to own: recorded for management visibility, but they do not
        // stop the clock and are never claimed against the company.
        ['technician_unavailable', 'No technician available', false, false],
        ['workshop_backlog', 'Workshop backlog', false, false],
        ['other', 'Other (explain in notes)', false, true],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->seedRoles($now);
        $this->seedServiceCenters($now);
        $this->seedSymptoms($now);
        $this->seedResolutions($now);
        $this->seedHoldReasons($now);
    }

    /**
     * Removes exactly the rows up() would have inserted. Global rows only
     * — a company's own override row (company_id NOT NULL) is left alone,
     * same philosophy as SeedStandardMasterData's down().
     */
    public function down(): void
    {
        $roleCodes = implode(',', array_map(fn(array $r) => "'{$r[0]}'", self::ROLES));
        $this->execute("DELETE FROM roles WHERE code IN ({$roleCodes})");

        $centerCodes = implode(',', array_map(fn(array $r) => "'{$r['code']}'", self::SERVICE_CENTERS));
        $this->execute("DELETE FROM service_centers WHERE code IN ({$centerCodes})");

        $symptomCodes = implode(',', array_map(fn(array $r) => "'{$r[0]}'", self::SYMPTOMS));
        $this->execute("DELETE FROM symptoms WHERE code IN ({$symptomCodes})");

        $resolutionCodes = implode(',', array_map(fn(array $r) => "'{$r[0]}'", self::RESOLUTIONS));
        $this->execute("DELETE FROM resolutions WHERE company_id IS NULL AND code IN ({$resolutionCodes})");

        $holdReasonCodes = implode(',', array_map(fn(array $r) => "'{$r[0]}'", self::HOLD_REASONS));
        $this->execute("DELETE FROM hold_reasons WHERE company_id IS NULL AND code IN ({$holdReasonCodes})");
    }

    private function seedRoles(string $now): void
    {
        $rows = [];
        foreach (self::ROLES as [$code, $name, $description, $permissions]) {
            $rows[] = [
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'permissions' => json_encode($permissions),
                'is_system' => 1,
                'created' => $now,
                'modified' => $now,
            ];
        }

        $this->table('roles')->insertOrSkip($rows)->save();
    }

    private function seedServiceCenters(string $now): void
    {
        $rows = [];
        foreach (self::SERVICE_CENTERS as $center) {
            $rows[] = $center + ['is_active' => 1, 'created' => $now, 'modified' => $now];
        }

        $this->table('service_centers')->insertOrSkip($rows)->save();
    }

    private function seedSymptoms(string $now): void
    {
        $ledTv = $this->fetchRow("SELECT id FROM product_categories WHERE code = 'led_tv' AND company_id IS NULL");
        $ledTvId = $ledTv ? (int)$ledTv['id'] : null;

        $rows = [];
        $order = 0;
        foreach (self::SYMPTOMS as [$code, $name, $panel, $video, $aliases]) {
            $rows[] = [
                'product_category_id' => $ledTvId,
                'code' => $code,
                'name' => $name,
                'aliases' => $aliases === [] ? null : json_encode($aliases),
                'is_panel_related' => (int)$panel,
                'requires_video_proof' => (int)$video,
                'sort_order' => ++$order,
                'is_active' => 1,
                'created' => $now,
                'modified' => $now,
            ];
        }

        $this->table('symptoms')->insertOrSkip($rows)->save();
    }

    private function seedResolutions(string $now): void
    {
        $rows = [];
        $order = 0;
        foreach (self::RESOLUTIONS as [$code, $name, $requiresSpare, $billable]) {
            $rows[] = [
                'company_id' => null,
                'code' => $code,
                'name' => $name,
                'requires_spare' => (int)$requiresSpare,
                'is_billable' => (int)$billable,
                'sort_order' => ++$order,
                'is_active' => 1,
                'created' => $now,
                'modified' => $now,
            ];
        }

        $this->table('resolutions')->insertOrSkip($rows)->save();
    }

    private function seedHoldReasons(string $now): void
    {
        $rows = [];
        $order = 0;
        foreach (self::HOLD_REASONS as [$code, $name, $pauses, $notice]) {
            $rows[] = [
                'company_id' => null,
                'code' => $code,
                'name' => $name,
                'pauses_sla' => (int)$pauses,
                'requires_company_notice' => (int)$notice,
                'sort_order' => ++$order,
                'is_active' => 1,
                'created' => $now,
                'modified' => $now,
            ];
        }

        $this->table('hold_reasons')->insertOrSkip($rows)->save();
    }
}
