<?php
declare(strict_types=1);

use App\Database\Migration\AppMigration;

/**
 * The platform's own standard vocabulary, tracked in phinxlog instead of
 * left to a manually-run seed.
 *
 * `config/Seeds/MasterListSeed.php` and `config/Seeds/DianoraSeed.php`
 * already build most of this by hand, but seeds are not run automatically
 * (only `bin/cake migrations migrate` runs on every container boot — see
 * docker/entrypoint.sh), so a fresh environment that nobody remembers to
 * seed has no job types, no categories and no geography at all. This
 * migration is the tracked, run-once version of that same baseline data,
 * covering everything on the list except one entry that turns out not to
 * need a row:
 *
 *   job type            job_types, company_id NULL (shared baseline)
 *   product category    product_categories, company_id NULL, incl. "Systems"
 *   brand               brands, seeded for the one company on the system
 *                        today (see seedCompanyBrands for why this can't
 *                        be a shared row)
 *   warranty scope       NOT a table — App\Domain\Enum\WarrantyScope is a
 *                        closed PHP enum (in_warranty / out_of_warranty /
 *                        not_applicable / unknown); there is nothing to
 *                        insert
 *   state                states, all 28 states + 8 union territories
 *   district             districts, all 14 Kerala districts
 *
 * `insertOrSkip()` is used throughout so this is also safe to run against
 * a database where someone already ran the old seed files by hand — the
 * unique code indexes just make the second write a no-op.
 */
class SeedStandardMasterData extends AppMigration
{
    /**
     * @var array<int, array{0: string, 1: string}>
     */
    private const STATES = [
        ['AP', 'Andhra Pradesh'],
        ['AR', 'Arunachal Pradesh'],
        ['AS', 'Assam'],
        ['BR', 'Bihar'],
        ['CG', 'Chhattisgarh'],
        ['GA', 'Goa'],
        ['GJ', 'Gujarat'],
        ['HR', 'Haryana'],
        ['HP', 'Himachal Pradesh'],
        ['JH', 'Jharkhand'],
        ['KA', 'Karnataka'],
        ['KL', 'Kerala'],
        ['MP', 'Madhya Pradesh'],
        ['MH', 'Maharashtra'],
        ['MN', 'Manipur'],
        ['ML', 'Meghalaya'],
        ['MZ', 'Mizoram'],
        ['NL', 'Nagaland'],
        ['OD', 'Odisha'],
        ['PB', 'Punjab'],
        ['RJ', 'Rajasthan'],
        ['SK', 'Sikkim'],
        ['TN', 'Tamil Nadu'],
        ['TG', 'Telangana'],
        ['TR', 'Tripura'],
        ['UP', 'Uttar Pradesh'],
        ['UK', 'Uttarakhand'],
        ['WB', 'West Bengal'],
        // Union territories.
        ['AN', 'Andaman and Nicobar Islands'],
        ['CH', 'Chandigarh'],
        ['DN', 'Dadra and Nagar Haveli and Daman and Diu'],
        ['DL', 'Delhi'],
        ['JK', 'Jammu and Kashmir'],
        ['LA', 'Ladakh'],
        ['LD', 'Lakshadweep'],
        ['PY', 'Puducherry'],
    ];

    /**
     * All 14 Kerala districts, with the alternate spellings a company's
     * own export is likely to send (same list as MasterListSeed).
     *
     * @var array<int, array{0: string, 1: string, 2: array<int, string>}>
     */
    private const KERALA_DISTRICTS = [
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

    /**
     * The shared product range every company starts from. "home_theatre"
     * from the original Dianora seed becomes "systems" here, at the
     * user's request, to cover audio/home-theatre/other systems under one
     * category rather than one named after a single product line.
     *
     * @var array<int, array{0: string, 1: string, 2: bool}>
     */
    private const PRODUCT_CATEGORIES = [
        ['led_tv', 'LED TV', true],
        ['washing_machine', 'Washing Machine', false],
        ['air_conditioner', 'Air Conditioner', false],
        ['systems', 'Systems (Home Theatre / Audio)', false],
        ['stabilizer', 'Stabilizer', false],
        ['cooktop', 'Infrared Cooktop', false],
        ['fan', 'Fan', false],
    ];

    /**
     * Our own job vocabulary, unchanged from CreateCatalog's doc comment —
     * the five job types that express a full Dianora-shaped rate card and
     * are meant to stay stable as further companies are onboarded.
     *
     * @var array<int, array{0: string, 1: string, 2: ?string, 3: bool, 4: bool, 5: bool}>
     */
    private const JOB_TYPES = [
        // code, name, description, size_banded, requires_warranty_scope, customer_billable
        ['installation', 'Installation', 'New unit installation at the customer site', true, false, false],
        ['demo_inspection', 'Demo / site inspection', null, false, false, false],
        ['service', 'Service call', 'Fault diagnosis and repair; rate depends on warranty scope', true, true, true],
        ['exchange_delivery', 'TV set exchange or delivery', null, false, true, false],
        [
            'panel_backlight',
            'Open cell & backlight replacement',
            'Panel-level repair; requires a skilled technician',
            false,
            true,
            true,
        ],
    ];

    /**
     * Seeds every standard list this migration owns.
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->seedStates($now);
        $this->seedKeralaDistricts($now);
        $this->seedProductCategories($now);
        $this->seedJobTypes($now);
        $this->seedCompanyBrands($now);
    }

    /**
     * Removes exactly the rows up() would have inserted. Deleting job
     * types, categories, districts or states that have since been
     * referenced elsewhere (rate cards, products, tickets) is correctly
     * refused by their foreign keys rather than cascading — same
     * philosophy as SeedAdminAuth's down(), which leaves the admin role
     * in place because other rows may reference it.
     */
    public function down(): void
    {
        $company = $this->fetchRow("SELECT id FROM companies WHERE code = 'DIANORA'");
        if ($company) {
            $this->execute(sprintf(
                "DELETE FROM brands WHERE company_id = %d AND code IN ('dianox', 'dianora')",
                (int)$company['id'],
            ));
        }

        $jobTypeCodes = implode(',', array_map(fn(array $r) => "'{$r[0]}'", self::JOB_TYPES));
        $this->execute("DELETE FROM job_types WHERE company_id IS NULL AND code IN ({$jobTypeCodes})");

        $categoryCodes = implode(',', array_map(fn(array $r) => "'{$r[0]}'", self::PRODUCT_CATEGORIES));
        $this->execute("DELETE FROM product_categories WHERE company_id IS NULL AND code IN ({$categoryCodes})");

        $this->execute(
            'DELETE d FROM districts d
                INNER JOIN states s ON s.id = d.state_id
             WHERE s.code = \'KL\'',
        );

        $stateCodes = implode(',', array_map(fn(array $r) => "'{$r[0]}'", self::STATES));
        $this->execute("DELETE FROM states WHERE code IN ({$stateCodes})");
    }

    /**
     * All 28 states and 8 union territories.
     */
    private function seedStates(string $now): void
    {
        $rows = [];
        foreach (self::STATES as [$code, $name]) {
            $rows[] = ['code' => $code, 'name' => $name, 'is_active' => 1, 'created' => $now, 'modified' => $now];
        }

        $this->table('states')->insertOrSkip($rows)->save();
    }

    /**
     * Deliberately Kerala only, same as MasterListSeed — the districts
     * that exist today are the ones the desk actually resolves addresses
     * against. Other states' districts can be added the same way once a
     * company operating outside Kerala is onboarded.
     */
    private function seedKeralaDistricts(string $now): void
    {
        $kerala = $this->fetchRow("SELECT id FROM states WHERE code = 'KL'");
        if (!$kerala) {
            return;
        }

        $keralaId = (int)$kerala['id'];
        $rows = [];
        foreach (self::KERALA_DISTRICTS as [$code, $name, $aliases]) {
            $rows[] = [
                'state_id' => $keralaId,
                'code' => $code,
                'name' => $name,
                'aliases' => $aliases === [] ? null : json_encode($aliases),
                'is_active' => 1,
                'created' => $now,
                'modified' => $now,
            ];
        }

        $this->table('districts')->insertOrSkip($rows)->save();
    }

    /**
     * The shared product category baseline, offered to every company.
     */
    private function seedProductCategories(string $now): void
    {
        $rows = [];
        $order = 0;
        foreach (self::PRODUCT_CATEGORIES as [$code, $name, $isSized]) {
            $rows[] = [
                'company_id' => null,
                'code' => $code,
                'name' => $name,
                'is_sized' => (int)$isSized,
                'sort_order' => ++$order,
                'is_active' => 1,
                'created' => $now,
                'modified' => $now,
            ];
        }

        $this->table('product_categories')->insertOrSkip($rows)->save();
    }

    /**
     * The shared job-type baseline the rate resolver matches on.
     */
    private function seedJobTypes(string $now): void
    {
        $rows = [];
        $order = 0;
        foreach (self::JOB_TYPES as [$code, $name, $description, $sizeBanded, $requiresScope, $billable]) {
            $rows[] = [
                'company_id' => null,
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'is_size_banded' => (int)$sizeBanded,
                'requires_warranty_scope' => (int)$requiresScope,
                'is_customer_billable' => (int)$billable,
                'sort_order' => ++$order,
                'is_active' => 1,
                'created' => $now,
                'modified' => $now,
            ];
        }

        $this->table('job_types')->insertOrSkip($rows)->save();
    }

    /**
     * Unlike job types and product categories, `brands` was never given a
     * nullable, NULL-means-shared owner column (see
     * ScopeSettingsToCompany's SCOPED_LISTS, which deliberately excludes
     * it) — every brand belongs to exactly one company, because a brand
     * name only means something in the context of who sells under it.
     * There is therefore no such thing as a "standard" brand independent
     * of a company.
     *
     * The practical answer is to seed the brands for the one company that
     * exists today (Dianora, same two brands as DianoraSeed), and to do
     * nothing if that company row is not present yet — this migration
     * must not fail on a fresh database that has not onboarded a company.
     */
    private function seedCompanyBrands(string $now): void
    {
        $company = $this->fetchRow("SELECT id FROM companies WHERE code = 'DIANORA'");
        if (!$company) {
            return;
        }

        $companyId = (int)$company['id'];

        $this->table('brands')->insertOrSkip([
            [
                'company_id' => $companyId,
                'code' => 'dianox',
                'name' => 'Dianox',
                'aliases' => json_encode(['DIANOX', 'Dianox', 'DIANOX LED']),
                'is_active' => 1,
                'created' => $now,
                'modified' => $now,
            ],
            [
                'company_id' => $companyId,
                'code' => 'dianora',
                'name' => 'Dianora',
                'aliases' => json_encode(['DIANORA', 'Dianora']),
                'is_active' => 1,
                'created' => $now,
                'modified' => $now,
            ],
        ])->save();
    }
}
