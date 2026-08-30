<?php
declare(strict_types=1);

use Migrations\BaseSeed;

/**
 * The controlled vocabularies, seeded from the shape of a real Dianora
 * ticket.
 *
 * Aliases matter more than they look. The company sends district and brand
 * as free text, and Kozhikode is spelt at least four different ways in the
 * wild ("Kozhikkode", "Calicut", "KOZHIKODE"). Every alias recorded here
 * is one import that resolves cleanly instead of landing in a review
 * queue for a human to fix by hand.
 *
 * Run this BEFORE any company seed — company data references these lists.
 *
 *   bin/cake seeds run MasterListSeed
 */
class MasterListSeed extends BaseSeed
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        // Order matters: symptoms reference product_categories, so the
        // catalogue has to exist before the coded fault list is built.
        $this->seedRoles($now);
        $this->seedServiceCenters($now);
        $this->seedStatesAndDistricts($now);
        $this->seedCatalog($now);
        $this->seedSymptoms($now);
        $this->seedResolutions($now);
        $this->seedHoldReasons($now);
    }

    private function seedStatesAndDistricts(string $now): void
    {
        $this->table('states')->insert([
            ['code' => 'KL', 'name' => 'Kerala', 'is_active' => 1, 'created' => $now, 'modified' => $now],
            ['code' => 'TN', 'name' => 'Tamil Nadu', 'is_active' => 1, 'created' => $now, 'modified' => $now],
            ['code' => 'KA', 'name' => 'Karnataka', 'is_active' => 1, 'created' => $now, 'modified' => $now],
            ['code' => 'PY', 'name' => 'Puducherry', 'is_active' => 1, 'created' => $now, 'modified' => $now],
        ])->save();

        $keralaId = (int)$this->fetchRow("SELECT id FROM states WHERE code = 'KL'")['id'];

        // All 14 Kerala districts, with the spellings and old names the
        // company's system actually emits.
        $districts = [
            ['TVM', 'Thiruvananthapuram', ['Trivandrum', 'TVPM']],
            ['KLM', 'Kollam', ['Quilon']],
            ['PTA', 'Pathanamthitta', []],
            ['ALP', 'Alappuzha', ['Alleppey']],
            ['KTM', 'Kottayam', []],
            ['IDK', 'Idukki', ['Idduki']],
            ['EKM', 'Ernakulam', ['Cochin', 'Kochi']],
            ['TSR', 'Thrissur', ['Trichur']],
            ['PKD', 'Palakkad', ['Palghat']],
            ['MLP', 'Malappuram', []],
            ['KKD', 'Kozhikode', ['Calicut', 'Kozhikkode', 'Kozikode']],
            ['WYD', 'Wayanad', ['Wynad']],
            ['KNR', 'Kannur', ['Cannanore']],
            ['KSD', 'Kasaragod', ['Kasargod', 'Kasaragode']],
        ];

        $rows = [];
        foreach ($districts as [$code, $name, $aliases]) {
            $rows[] = [
                'state_id' => $keralaId,
                'code' => $code,
                'name' => $name,
                'aliases' => $aliases === [] ? null : json_encode($aliases),
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ];
        }

        $this->table('districts')->insert($rows)->save();
    }

    /**
     * Coded faults.
     *
     * The sample ticket's description was "DISPLAY LINE NEED SYMPTOM VEDIO
     * DOC.. PENDING" — one coded symptom (display_line), one workflow flag
     * (video proof outstanding), and a lot of noise. Coding it is what
     * makes repeat-complaint detection under clause 7 possible at all: to
     * know a customer is back with the SAME fault, the fault has to be a
     * value rather than a sentence.
     */
    private function seedSymptoms(string $now): void
    {
        $ledTvId = (int)$this->fetchRow("SELECT id FROM product_categories WHERE code = 'led_tv'")['id'];

        $symptoms = [
            // code, name, panel related, needs video, aliases
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

        $rows = [];
        $order = 0;
        foreach ($symptoms as [$code, $name, $panel, $video, $aliases]) {
            $rows[] = [
                'product_category_id' => $ledTvId,
                'code' => $code,
                'name' => $name,
                'aliases' => $aliases === [] ? null : json_encode($aliases),
                'is_panel_related' => (int)$panel,
                'requires_video_proof' => (int)$video,
                'sort_order' => ++$order,
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ];
        }

        $this->table('symptoms')->insert($rows)->save();
    }

    /**
     * How a job ended.
     *
     * `is_billable` is the column that earns its keep: "no fault found" and
     * "customer cancelled" are perfectly legitimate closures that earn
     * nothing, and the desk needs to know that while the technician is
     * still on site — not when the invoice comes back short.
     */
    private function seedResolutions(string $now): void
    {
        $resolutions = [
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

        $rows = [];
        $order = 0;
        foreach ($resolutions as [$code, $name, $requiresSpare, $billable]) {
            $rows[] = [
                'code' => $code,
                'name' => $name,
                'requires_spare' => (int)$requiresSpare,
                'is_billable' => (int)$billable,
                'sort_order' => ++$order,
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ];
        }

        $this->table('resolutions')->insert($rows)->save();
    }

    /**
     * Why the SLA clock stopped.
     *
     * `pauses_sla` is the honest part of this table. A customer who is
     * away stops the clock; our own shortage of technicians does not. If
     * every reason paused the clock, our SLA numbers would be fiction and
     * the first company audit would say so.
     */
    private function seedHoldReasons(string $now): void
    {
        $reasons = [
            // code, name, pauses SLA, needs company notice
            ['customer_unavailable', 'Customer unavailable', true, true],
            ['customer_postponed', 'Customer asked to postpone', true, true],
            ['address_wrong', 'Address or contact number incorrect', true, true],
            ['access_denied', 'No access to the site', true, true],
            ['video_proof_awaited', 'Awaiting symptom video from customer', true, true],
            ['spare_awaited', 'Awaiting spare part from company', true, true],
            ['company_approval_pending', 'Awaiting company approval or estimate', true, true],
            ['estimate_with_customer', 'Estimate with customer for approval', true, true],
            // These are ours to own. They are recorded for management
            // visibility, but they do not stop the clock and do not get
            // claimed against the company.
            ['technician_unavailable', 'No technician available', false, false],
            ['workshop_backlog', 'Workshop backlog', false, false],
            ['other', 'Other (explain in notes)', false, true],
        ];

        $rows = [];
        $order = 0;
        foreach ($reasons as [$code, $name, $pauses, $notice]) {
            $rows[] = [
                'code' => $code,
                'name' => $name,
                'pauses_sla' => (int)$pauses,
                'requires_company_notice' => (int)$notice,
                'sort_order' => ++$order,
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ];
        }

        $this->table('hold_reasons')->insert($rows)->save();
    }

    private function seedRoles(string $now): void
    {
        $this->table('roles')->insert([
            [
                'code' => 'admin',
                'name' => 'Administrator',
                'description' => 'Full access including rate cards and SLA rules',
                'permissions' => json_encode(['*']),
                'is_system' => 1,
                'created' => $now, 'modified' => $now,
            ],
            [
                'code' => 'desk',
                'name' => 'Service desk',
                'description' => 'Intake, assignment, holds and company correspondence',
                'permissions' => json_encode([
                    'tickets.*', 'customers.*', 'holds.*', 'technicians.view', 'reports.view',
                ]),
                'is_system' => 1,
                'created' => $now, 'modified' => $now,
            ],
            [
                'code' => 'technician',
                'name' => 'Field technician',
                // Deliberately narrow. The field app shows one person's own
                // jobs and nothing else — no customer list, no rates, no
                // margins.
                'permissions' => json_encode([
                    'tickets.own.view', 'tickets.own.checkin', 'tickets.own.close',
                    'attachments.upload', 'collections.create',
                ]),
                'is_system' => 1,
                'created' => $now, 'modified' => $now,
            ],
            [
                'code' => 'accounts',
                'name' => 'Accounts',
                'description' => 'Invoicing, payouts, cash reconciliation, credit exposure',
                'permissions' => json_encode([
                    'invoices.*', 'payouts.*', 'collections.*', 'reports.*', 'tickets.view',
                ]),
                'is_system' => 1,
                'created' => $now, 'modified' => $now,
            ],
        ])->save();
    }

    /**
     * Our own branches. The sample ticket was assigned to "GRAND SERVICE
     * THARASSERY", which is why this is a list rather than a single row —
     * and why travel is computed per branch, since the free 15km radius is
     * measured from whichever centre took the job.
     */
    private function seedServiceCenters(string $now): void
    {
        $this->table('service_centers')->insert([
            [
                'code' => 'THA',
                'name' => 'GRAND SERVICE THARASSERY',
                'city' => 'Thamarassery',
                'district' => 'Kozhikode',
                'state' => 'Kerala',
                'pincode' => '673573',
                'latitude' => '11.4090000',
                'longitude' => '75.9370000',
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
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
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ],
        ])->save();
    }

    private function seedCatalog(string $now): void
    {
        // "The below mentioned are our product ranges" — agreement page 1.
        $this->table('product_categories')->insert([
            ['code' => 'led_tv', 'name' => 'LED TV', 'is_sized' => 1, 'sort_order' => 1,
                'is_active' => 1, 'created' => $now, 'modified' => $now],
            ['code' => 'washing_machine', 'name' => 'Washing Machine', 'is_sized' => 0, 'sort_order' => 2,
                'is_active' => 1, 'created' => $now, 'modified' => $now],
            ['code' => 'home_theatre', 'name' => 'Home Theatre System', 'is_sized' => 0, 'sort_order' => 3,
                'is_active' => 1, 'created' => $now, 'modified' => $now],
            ['code' => 'stabilizer', 'name' => 'Stabilizer', 'is_sized' => 0, 'sort_order' => 4,
                'is_active' => 1, 'created' => $now, 'modified' => $now],
            ['code' => 'air_conditioner', 'name' => 'Air Conditioner', 'is_sized' => 0, 'sort_order' => 5,
                'is_active' => 1, 'created' => $now, 'modified' => $now],
            ['code' => 'cooktop', 'name' => 'Infrared Cooktop', 'is_sized' => 0, 'sort_order' => 6,
                'is_active' => 1, 'created' => $now, 'modified' => $now],
            ['code' => 'fan', 'name' => 'Fan', 'is_sized' => 0, 'sort_order' => 7,
                'is_active' => 1, 'created' => $now, 'modified' => $now],
        ])->save();

        /*
         * Our own job vocabulary — the key the rate resolver matches on.
         * Five job types express the entire Dianora card, and they are
         * intended to stay stable as further companies are onboarded.
         */
        $this->table('job_types')->insert([
            [
                'code' => 'installation',
                'name' => 'Installation',
                'description' => 'New unit installation at the customer site',
                'is_size_banded' => 1,
                'requires_warranty_scope' => 0,
                'is_customer_billable' => 0,
                'sort_order' => 1, 'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ],
            [
                'code' => 'demo_inspection',
                'name' => 'Demo / site inspection',
                'is_size_banded' => 0,
                'requires_warranty_scope' => 0,
                'is_customer_billable' => 0,
                'sort_order' => 2, 'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ],
            [
                'code' => 'service',
                'name' => 'Service call',
                'description' => 'Fault diagnosis and repair; rate depends on warranty scope',
                'is_size_banded' => 1,
                'requires_warranty_scope' => 1,
                'is_customer_billable' => 1,
                'sort_order' => 3, 'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ],
            [
                'code' => 'exchange_delivery',
                'name' => 'TV set exchange or delivery',
                'is_size_banded' => 0,
                'requires_warranty_scope' => 1,
                'is_customer_billable' => 0,
                'sort_order' => 4, 'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ],
            [
                'code' => 'panel_backlight',
                'name' => 'Open cell & backlight replacement',
                'description' => 'Panel-level repair; requires a skilled technician',
                'is_size_banded' => 0,
                'requires_warranty_scope' => 1,
                'is_customer_billable' => 1,
                'sort_order' => 5, 'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ],
        ])->save();
    }
}
