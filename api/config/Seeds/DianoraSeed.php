<?php
declare(strict_types=1);

use Migrations\BaseSeed;

/**
 * Seeds the system from the signed Dianora Electronics agreement.
 *
 * Everything here is transcribed from Service Agreement 2.pdf. It doubles
 * as the reference implementation for onboarding company #2: nothing in
 * this file is code the next company would need changed, only data.
 *
 * The shared baseline it overrides (resolutions, hold reasons, product
 * categories, job types) is seeded by migrations
 * `SeedStandardMasterData` / `SeedOperationalMasterLists`, which already
 * run on every container boot — no separate seed needs to run first.
 *
 *   bin/cake seeds run DianoraSeed
 */
class DianoraSeed extends BaseSeed
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $companyId = $this->seedCompany($now);
        $this->seedBrands($companyId, $now);
        $this->seedCompanyJobTypeAliases($companyId, $now);
        $agreementId = $this->seedAgreement($companyId, $now);
        $cardId = $this->seedRateCard($companyId, $agreementId, $now);
        $this->seedRateCardItems($cardId, $now);
        $this->seedSlaRules($cardId, $now);
        $this->seedProducts($companyId, $now);
        $this->seedSpareParts($companyId, $now);
        $this->seedSettings($companyId, $now);
        $this->seedMasterListOverrides($companyId, $now);
    }

    /**
     * Dianora's own operational settings.
     *
     * Only the ones where Dianora actually differs from the platform
     * default are written. A row holding the current default looks
     * identical but behaves differently: it pins the value, so a later
     * change to the default silently passes this company by. An override
     * should exist because someone chose it.
     */
    private function seedSettings(int $companyId, string $now): void
    {
        $settings = [
            // Their own tickets read DN1407260024. Ours are separate, but
            // the desk reads both on one screen, so making ours obviously
            // ours is what stops them being confused on a phone call.
            ['ticket.number_prefix', 'string', 'DIN'],

            // Warranty status is decided from the serial and the bill date,
            // and Dianora reimburses in-warranty work only on proof of
            // both. Intake without them cannot be priced later.
            ['ticket.require_serial_no', 'boolean', '1'],
            ['ticket.require_bill_date', 'boolean', '1'],

            // Clause 11 makes email the only valid channel, so a hold the
            // company was not emailed about will not survive a dispute.
            ['notice.email_company_on_hold', 'boolean', '1'],

            // Both closure gates are off, for the same reason: much of this
            // work is in rural Kozhikode where a customer may have no signal
            // and a technician may have no working camera, and a gate the
            // field cannot pass teaches them to stop using the app. Photos
            // can still be attached — and should be, they are what settles a
            // dispute — they are just not a condition of closing. Turn
            // either on per company once the field app is reliably capturing
            // them.
            ['closure.require_photo', 'boolean', '0'],
            ['closure.require_customer_otp', 'boolean', '0'],

            ['brand.portal_name', 'string', 'Dianora Service Desk'],
        ];

        $rows = [];
        foreach ($settings as [$key, $type, $value]) {
            $rows[] = [
                'company_id' => $companyId,
                'setting_key' => $key,
                'value_type' => $type,
                'value' => $value,
                'is_editable' => 1,
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ];
        }

        $this->table('company_settings')->insert($rows)->save();
    }

    /**
     * Where Dianora's vocabulary diverges from the shared baseline.
     *
     * Both rows below reuse a code that already exists on a shared row.
     * That is the mechanism, not an accident: the company row shadows the
     * shared one, so the rate resolver still matches on a stable code
     * while the behaviour behind it differs per company.
     */
    private function seedMasterListOverrides(int $companyId, string $now): void
    {
        $sharedHold = $this->fetchRow(
            "SELECT id, code, name, description, sort_order
               FROM hold_reasons
              WHERE code = 'video_proof_awaited' AND company_id IS NULL",
        );

        if ($sharedHold !== false && $sharedHold !== null) {
            $this->table('hold_reasons')->insert([[
                'company_id' => $companyId,
                'code' => $sharedHold['code'],
                'name' => 'Awaiting symptom video from customer',
                'description' => 'Dianora accepts a customer video as evidence while the clock is stopped.',
                // The shared row stops the clock; so does this one. What
                // differs is the notice requirement — clause 11 means this
                // pause is only defensible if Dianora were emailed about it.
                'pauses_sla' => 1,
                'requires_company_notice' => 1,
                'sort_order' => (int)$sharedHold['sort_order'],
                'is_active' => 1,
                'override_note' => 'Clause 11: the pause needs an email to Dianora to survive a dispute.',
                'created' => $now, 'modified' => $now,
            ]])->save();
        }

        $sharedResolution = $this->fetchRow(
            "SELECT code, sort_order FROM resolutions WHERE code = 'no_fault_found' AND company_id IS NULL",
        );

        if ($sharedResolution !== false && $sharedResolution !== null) {
            $this->table('resolutions')->insert([[
                'company_id' => $companyId,
                'code' => $sharedResolution['code'],
                'name' => 'No fault found',
                'description' => 'Dianora does not pay a visit charge where no fault is demonstrated.',
                'requires_spare' => 0,
                // The dial that matters: a non-billable closure produces no
                // ledger at all, and the desk is told before the technician
                // leaves site rather than at invoice time.
                'is_billable' => 0,
                'sort_order' => (int)$sharedResolution['sort_order'],
                'is_active' => 1,
                'override_note' => 'Dianora will not reimburse a no-fault-found visit.',
                'created' => $now, 'modified' => $now,
            ]])->save();
        }
    }

    /**
     * "Product: Dianox" on the sample ticket. Dianora assigns work under
     * sub-brands, and the aliases cover the casing and spelling variants
     * their export actually produces.
     */
    private function seedBrands(int $companyId, string $now): void
    {
        $this->table('brands')->insert([
            [
                'company_id' => $companyId,
                'code' => 'dianox',
                'name' => 'Dianox',
                'aliases' => json_encode(['DIANOX', 'Dianox', 'DIANOX LED']),
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ],
            [
                'company_id' => $companyId,
                'code' => 'dianora',
                'name' => 'Dianora',
                'aliases' => json_encode(['DIANORA', 'Dianora']),
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ],
        ])->save();
    }

    /**
     * Dianora's "Complaint Type" wording, mapped onto our job types.
     *
     * The sample ticket says "Complaint Type: Service", which has to
     * become our `service` job type at import. Where a company encodes
     * warranty status into the complaint type itself, the alias carries
     * the scope too, so the importer never has to guess who to bill.
     */
    private function seedCompanyJobTypeAliases(int $companyId, string $now): void
    {
        $jobTypes = $this->jobTypeIds();

        /*
         * No case variants needed. The column collates as
         * utf8mb4_unicode_ci, so "Service", "SERVICE" and "service" all
         * match this one row — which is the behaviour we want, since the
         * company's export is inconsistent about casing.
         */
        $aliases = [
            // company's label, our job type, warranty scope override
            ['Service', 'service', null],
            ['Service Call', 'service', null],
            ['Breakdown', 'service', null],
            ['In Warranty Service', 'service', 'in_warranty'],
            ['Out of Warranty Service', 'service', 'out_of_warranty'],
            ['OOW Service', 'service', 'out_of_warranty'],
            ['Installation', 'installation', null],
            ['Fixing', 'installation', null],
            ['Demo', 'demo_inspection', null],
            ['Site Inspection', 'demo_inspection', null],
            ['Exchange', 'exchange_delivery', null],
            ['Delivery', 'exchange_delivery', null],
            ['Panel Replacement', 'panel_backlight', null],
            ['Open Cell Replacement', 'panel_backlight', null],
        ];

        $rows = [];
        foreach ($aliases as [$label, $jobType, $scope]) {
            $rows[] = [
                'company_id' => $companyId,
                'job_type_id' => $jobTypes[$jobType],
                'company_label' => $label,
                'warranty_scope' => $scope,
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ];
        }

        $this->table('company_job_type_aliases')->insert($rows)->save();
    }

    private function seedCompany(string $now): int
    {
        $this->table('companies')->insert([[
            'code' => 'DIANORA',
            'name' => 'Dianora',
            'legal_name' => 'DIANORA ELECTRONICS PVT LTD',
            'contact_person' => 'Sunil Kumar Y',
            'phone' => '18004250125',
            'support_phone' => '04772261448',
            // Clause 11 makes email the only valid channel, so this address
            // is the address of record for every notice we serve.
            'communication_email' => 'service@dianora.example',
            'accounts_email' => 'accounts@dianora.example',
            'address_line1' => 'Emmar Building, Aksharanagari Road',
            'address_line2' => 'Punnapara',
            'city' => 'Alappuzha',
            'state' => 'Kerala',
            'is_active' => 1,
            'onboarded_on' => date('Y-m-d'),
            'notes' => 'LED TV and home appliances. Formed August 2017, operating in South India.',
            'created' => $now, 'modified' => $now,
        ]])->save();

        return (int)$this->fetchRow("SELECT id FROM companies WHERE code = 'DIANORA'")['id'];
    }

    /**
     * Every commercial term from the agreement, as data.
     */
    private function seedAgreement(int $companyId, string $now): int
    {
        $this->table('company_agreements')->insert([[
            'company_id' => $companyId,
            'agreement_no' => 'DIANORA-SA-2',
            'title' => 'Dianora Electronics service centre agreement',
            'status' => 'active',
            'effective_from' => date('Y-m-d'),
            'effective_to' => null,

            'credit_limit_paise' => 2_500_000,   // clause 4  — Rs.25,000
            'invoice_cycle_day' => 10,           // clause 5  — settled on the 10th
            'oow_royalty_pct' => '10.00',        // clause 8  — 10% royalty
            'spare_margin_min_pct' => '10.00',   // clause 6  — 10% to 15%
            'spare_margin_max_pct' => '15.00',
            'travel_free_km' => '15.00',         // note 5    — Rs.3/km beyond 15km
            'travel_rate_per_km_paise' => 300,

            'sla_contact_hours' => 2,            // clause 1
            'sla_visit_hours' => 48,             // clause 2
            'sla_close_hours' => 48,             // clause 3
            'repeat_complaint_window_days' => 90, // clause 7 — 3 months
            'defective_return_days' => 7,        // clause 9
            'spare_billing_days' => 30,          // clause 10
            'notice_period_days' => 60,          // note 2   — 2 months

            // Not from the agreement — these are our own policy towards the
            // field on Dianora work, and they are the reason a technician
            // has any reason to close a job inside 24 hours. Passing the
            // whole incentive on and recovering none of the deduction is the
            // generous end of the range; both are dials, set per company.
            'technician_bonus_share_pct' => '100.00',
            'technician_penalty_recovery_pct' => '0.00',

            // Clause 8 says "service charges". Read narrowly here, because
            // clause 6 deals with spares separately — but it is Dianora's
            // reading, recorded as theirs rather than as the system's.
            'royalty_applies_to_spares' => 0,

            'document_path' => 'Service Agreement 2.pdf',
            'created' => $now, 'modified' => $now,
        ]])->save();

        return (int)$this->fetchRow(
            "SELECT id FROM company_agreements WHERE agreement_no = 'DIANORA-SA-2'",
        )['id'];
    }

    private function seedRateCard(int $companyId, int $agreementId, string $now): int
    {
        $this->table('rate_cards')->insert([[
            'company_id' => $companyId,
            'company_agreement_id' => $agreementId,
            'name' => 'Dianora service policy v1',
            'version' => 1,
            'status' => 'active',
            'effective_from' => date('Y-m-d'),
            'currency' => 'INR',
            'published_at' => $now,
            'notes' => 'Transcribed from the SERVICE POLICY tables in Service Agreement 2.pdf.',
            'created' => $now, 'modified' => $now,
        ]])->save();

        return (int)$this->fetchRow(sprintf(
            'SELECT id FROM rate_cards WHERE company_id = %d AND version = 1',
            $companyId,
        ))['id'];
    }

    /**
     * The eleven priced lines on the card.
     *
     * Note that the size bands are inconsistent between sections — 45-65
     * for installation, 45-85 for in-warranty service, 45-55 and 65-85 for
     * out-of-warranty. That is why bands are per-item inch ranges rather
     * than a shared lookup table.
     *
     * It also leaves two genuine gaps, which the resolver will refuse to
     * price rather than guess at:
     *   - 44" is unpriced everywhere (bands stop at 43 and restart at 45)
     *   - 56"-64" is unpriced for out-of-warranty service
     * Both need confirming with Dianora by email.
     */
    private function seedRateCardItems(int $cardId, string $now): void
    {
        $jobTypes = $this->jobTypeIds();
        $rows = [];

        $add = function (
            string $jobType,
            string $scope,
            int $rupees,
            string $payer,
            string $label,
            ?string $min = null,
            ?string $max = null,
        ) use (&$rows, $jobTypes, $cardId, $now): void {
            $rows[] = [
                'rate_card_id' => $cardId,
                'job_type_id' => $jobTypes[$jobType],
                'product_category_id' => null,
                'warranty_scope' => $scope,
                'size_min_inch' => $min,
                'size_max_inch' => $max,
                'amount_paise' => $rupees * 100,
                'payer' => $payer,
                'label' => $label,
                'priority' => 100,
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ];
        };

        // ---- INSTALLATION (billed to the company) --------------------
        $add('installation', 'not_applicable', 350, 'company', 'Installation 24"-43"', '24.00', '43.00');
        $add('installation', 'not_applicable', 500, 'company', 'Installation 45"-65"', '45.00', '65.00');
        $add('demo_inspection', 'not_applicable', 250, 'company', 'Demo / site inspection');

        // ---- SERVICE IN WARRANTY (billed to the company) -------------
        $add('service', 'in_warranty', 400, 'company', 'Service in warranty 24"-43"', '24.00', '43.00');
        $add('service', 'in_warranty', 500, 'company', 'Service in warranty 45"-85"', '45.00', '85.00');
        $add('exchange_delivery', 'in_warranty', 700, 'company', 'TV set exchange or delivery');
        $add('panel_backlight', 'in_warranty', 1000, 'company', 'Open cell & backlight replacement and service');

        // ---- SERVICE OUT OF WARRANTY (collected from the customer) --
        $add('service', 'out_of_warranty', 500, 'customer', 'Service out of warranty 24"-43"', '24.00', '43.00');
        $add('service', 'out_of_warranty', 1000, 'customer', 'Service out of warranty 45"-55"', '45.00', '55.00');
        $add('service', 'out_of_warranty', 1500, 'customer', 'Service out of warranty 65"-85"', '65.00', '85.00');
        $add(
            'panel_backlight',
            'out_of_warranty',
            1500,
            'customer',
            'Open cell & backlight replacement and service (out of warranty)',
        );

        $this->table('rate_card_items')->insert($rows)->save();
    }

    /**
     * The four SLA rules.
     *
     * Priority is what makes the two in-warranty closure incentives
     * mutually exclusive: the tighter 48h band is evaluated first and,
     * being non-stackable, stops the walk before the 72h band is reached.
     */
    private function seedSlaRules(int $cardId, string $now): void
    {
        $this->table('sla_rules')->insert([
            [
                'rate_card_id' => $cardId,
                'code' => 'install_close_24',
                'label' => 'Installation closed within 24 hours',
                'kind' => 'bonus',
                'metric' => 'hours_to_close',
                'comparator' => 'lte',
                'threshold_from_hours' => null,
                'threshold_to_hours' => '24.00',
                'amount_paise' => 5_000,
                'applies_to_job_types' => json_encode(['installation']),
                'warranty_scope' => null,
                'priority' => 10,
                'is_stackable' => 0,
                'is_active' => 1,
                'notes' => 'Agreement: "Additional Rs.50 (if case closed within 24 hr)".',
                'created' => $now, 'modified' => $now,
            ],
            [
                'rate_card_id' => $cardId,
                'code' => 'install_late_48',
                'label' => 'Installation completed after 48 hours',
                'kind' => 'penalty',
                'metric' => 'hours_to_close',
                'comparator' => 'gt',
                'threshold_from_hours' => '48.00',
                'threshold_to_hours' => null,
                'amount_paise' => 5_000,
                'applies_to_job_types' => json_encode(['installation']),
                'warranty_scope' => null,
                'priority' => 20,
                'is_stackable' => 0,
                'is_active' => 1,
                'notes' => 'Agreement: "24\" to 65\" Installation after 48 Hrs will deduct Rs.50".',
                'created' => $now, 'modified' => $now,
            ],
            [
                'rate_card_id' => $cardId,
                'code' => 'service_close_48',
                'label' => 'Complaint closed within 48 hours',
                'kind' => 'bonus',
                'metric' => 'hours_to_close',
                'comparator' => 'lte',
                'threshold_from_hours' => null,
                'threshold_to_hours' => '48.00',
                'amount_paise' => 7_500,
                'applies_to_job_types' => json_encode(['service']),
                'warranty_scope' => 'in_warranty',
                'priority' => 10,
                'is_stackable' => 0,
                'is_active' => 1,
                'notes' => 'Agreement: "Additional Service Benefit for closing the complaint within 48 Hrs".',
                'created' => $now, 'modified' => $now,
            ],
            [
                'rate_card_id' => $cardId,
                'code' => 'service_close_72',
                'label' => 'Complaint closed after 48 and within 72 hours',
                'kind' => 'bonus',
                'metric' => 'hours_to_close',
                'comparator' => 'between',
                'threshold_from_hours' => '48.00',
                'threshold_to_hours' => '72.00',
                'amount_paise' => 5_000,
                'applies_to_job_types' => json_encode(['service']),
                'warranty_scope' => 'in_warranty',
                'priority' => 20,
                'is_stackable' => 0,
                'is_active' => 1,
                'notes' => 'Agreement: "After 48 Hrs & within 72 Hrs — Rs.50".',
                'created' => $now, 'modified' => $now,
            ],
        ])->save();
    }

    /**
     * The model catalogue.
     *
     * This is the table that makes size-banded pricing work. The company
     * sends a model code ("Model: DX4325FHDSVK") and rarely a screen size,
     * but the rate depends entirely on the size — so the model must resolve
     * to one. Without this lookup, every ticket would need a human to read
     * "43" out of the model string, and a 44" typo would go unnoticed until
     * the resolver refused to price it.
     *
     * Warranty length is per model too: "3 years, 1 & 2 years depends upon
     * the model".
     */
    private function seedProducts(int $companyId, string $now): void
    {
        $categories = $this->categoryIds();
        $brands = $this->brandIds($companyId);
        $rows = [];

        // Dianox DX series — the naming on the sample ticket, where
        // DX4325FHDSVK is a 43" full-HD set.
        $dianoxModels = [
            ['DX3225HDSVK', 32, 'HD', 24],
            ['DX4325FHDSVK', 43, 'FHD', 36],
            ['DX5025UHDSVK', 50, '4K UHD', 36],
            ['DX5525UHDSVK', 55, '4K UHD', 36],
            ['DX6525UHDSVK', 65, '4K UHD', 36],
        ];

        foreach ($dianoxModels as [$model, $size, $panel, $warrantyMonths]) {
            $rows[] = [
                'company_id' => $companyId,
                'brand_id' => $brands['dianox'],
                'product_category_id' => $categories['led_tv'],
                'model_no' => $model,
                'name' => sprintf('Dianox %d" %s LED TV', $size, $panel),
                'size_inch' => sprintf('%d.00', $size),
                'warranty_months' => $warrantyMonths,
                'panel_warranty_months' => $warrantyMonths,
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ];
        }

        // Dianora-branded range, including the 75" and 85" sets the rate
        // card prices but which have no out-of-warranty band below 65".
        $dianoraModels = [
            ['DN-24LED', 24, 12],
            ['DN-32LED', 32, 24],
            ['DN-43LED', 43, 36],
            ['DN-55LED', 55, 36],
            ['DN-65LED', 65, 36],
            ['DN-75LED', 75, 36],
            ['DN-85LED', 85, 36],
        ];

        foreach ($dianoraModels as [$model, $size, $warrantyMonths]) {
            $rows[] = [
                'company_id' => $companyId,
                'brand_id' => $brands['dianora'],
                'product_category_id' => $categories['led_tv'],
                'model_no' => $model,
                'name' => sprintf('Dianora %d" LED TV', $size),
                'size_inch' => sprintf('%d.00', $size),
                'warranty_months' => $warrantyMonths,
                'panel_warranty_months' => $warrantyMonths,
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ];
        }

        $this->table('products')->insert($rows)->save();
    }

    /**
     * @return array<string, int>
     */
    private function brandIds(int $companyId): array
    {
        $rows = $this->fetchAll(sprintf('SELECT id, code FROM brands WHERE company_id = %d', $companyId));
        $map = [];
        foreach ($rows as $row) {
            $map[$row['code']] = (int)$row['id'];
        }

        return $map;
    }

    private function seedSpareParts(int $companyId, string $now): void
    {
        $categories = $this->categoryIds();

        $this->table('spare_parts')->insert([
            [
                'company_id' => $companyId,
                'product_category_id' => $categories['led_tv'],
                'part_no' => 'DN-PANEL-43',
                'name' => 'Open cell panel 43"',
                'cost_paise' => 650_000,
                'mrp_paise' => 850_000,
                'is_serialized' => 1,
                'reorder_level' => 2,
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ],
            [
                'company_id' => $companyId,
                'product_category_id' => $categories['led_tv'],
                'part_no' => 'DN-BLSTRIP-43',
                'name' => 'Backlight strip set 43"',
                'cost_paise' => 90_000,
                'mrp_paise' => 130_000,
                'is_serialized' => 0,
                'reorder_level' => 5,
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ],
            [
                'company_id' => $companyId,
                'product_category_id' => $categories['led_tv'],
                'part_no' => 'DN-MBOARD-UNI',
                'name' => 'Universal main board',
                'cost_paise' => 180_000,
                'mrp_paise' => 240_000,
                'is_serialized' => 1,
                'reorder_level' => 3,
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ],
            [
                'company_id' => $companyId,
                'product_category_id' => $categories['led_tv'],
                'part_no' => 'DN-PSU-UNI',
                'name' => 'Power supply board',
                'cost_paise' => 95_000,
                'mrp_paise' => 135_000,
                'is_serialized' => 0,
                'reorder_level' => 4,
                'is_active' => 1,
                'created' => $now, 'modified' => $now,
            ],
        ])->save();
    }

    /**
     * @return array<string, int>
     */
    private function jobTypeIds(): array
    {
        return $this->lookup('job_types');
    }

    /**
     * @return array<string, int>
     */
    private function categoryIds(): array
    {
        return $this->lookup('product_categories');
    }

    /**
     * @return array<string, int>
     */
    private function lookup(string $table): array
    {
        $rows = $this->fetchAll(sprintf('SELECT id, code FROM %s', $table));
        $map = [];
        foreach ($rows as $row) {
            $map[$row['code']] = (int)$row['id'];
        }

        return $map;
    }
}
