<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\SpareService;
use App\Service\TicketClosureService;
use App\Service\TicketWorkflow;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Seed 100 realistic tickets with high variety across:
 * - Companies (Dianora, Samsung, LG, Whirlpool, Sony)
 * - Warranties (In Warranty, Out of Warranty, Extended Warranty)
 * - Technicians / Engineers
 * - Appliances & Product Categories
 * - Symptoms, Diagnoses & Resolutions
 * - Statuses (Received, Assigned, In Progress, On Hold, Closed)
 * - Spare Parts & Stock Consumptions
 */
class SeedTicketsCommand extends Command
{
    use LocatorAwareTrait;

    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription('Seed 100 diverse tickets into the database with spares, technicians, and lifecycle events.')
            ->addOption('count', [
                'short' => 'c',
                'help' => 'Number of tickets to generate',
                'default' => '100',
            ]);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        \Cake\Cache\Cache::clearAll();
        $count = (int)$args->getOption('count');
        $io->out(sprintf('<info>Starting generation of %d diverse tickets...</info>', $count));

        // 1. Ensure master companies exist
        $companies = $this->ensureCompanies();
        $this->syncSequences();
        // 2. Ensure service centers
        $centers = $this->ensureServiceCenters();
        // 3. Ensure technicians
        $technicians = $this->ensureTechnicians($centers);
        // 4. Ensure master product categories, job types, symptoms, resolutions
        $jobTypes = $this->ensureJobTypes();
        $categories = $this->ensureCategories();
        $symptoms = $this->ensureSymptoms();
        $resolutions = $this->ensureResolutions();
        $sparesByCompany = $this->ensureSpares($companies);
        $brandsByCompany = $this->ensureBrands($companies);

        $workflow = new TicketWorkflow();
        $spareService = new SpareService();
        $closureService = new TicketClosureService();

        $firstNames = ['Rahul', 'Priya', 'Arjun', 'Anjali', 'Kiran', 'Sneha', 'Vishnu', 'Meera', 'Gautam', 'Kavya', 'Siddharth', 'Lakshmi', 'Nivin', 'Rhea', 'Fahad', 'Aiswarya', 'Rohan', 'Divya', 'Suresh', 'Bhavana'];
        $lastNames = ['Nair', 'Menon', 'Kurup', 'Pillai', 'Varma', 'Nambiar', 'Sharman', 'Thomas', 'Joseph', 'Mathew', 'Patel', 'Reddy', 'Rao', 'Das', 'Khan', 'Iyer'];
        // Districts are looked up by code, never numbered by hand: the master
        // list is alphabetical, so the ids 1-5 this used to hardcode landed on
        // five unrelated districts and every seeded customer ended up in a
        // district that contradicted their own city and pincode.
        $districtIds = $this->districtIdsByCode();
        $cities = array_values(array_filter([
            ['code' => 'EKM', 'city' => 'Kochi', 'pincodes' => ['682001', '682016', '682020', '682030']],
            ['code' => 'KKD', 'city' => 'Kozhikode', 'pincodes' => ['673001', '673004', '673016']],
            ['code' => 'TVM', 'city' => 'Trivandrum', 'pincodes' => ['695001', '695004', '695014']],
            ['code' => 'TSR', 'city' => 'Thrissur', 'pincodes' => ['680001', '680005', '680020']],
            ['code' => 'KTM', 'city' => 'Kottayam', 'pincodes' => ['686001', '686004']],
        ], fn (array $row): bool => isset($districtIds[$row['code']])));

        if ($cities === []) {
            $io->error('No matching districts in the master list. Run the master list seed first.');

            return self::CODE_ERROR;
        }

        $statuses = ['received', 'assigned', 'in_progress', 'on_hold', 'closed'];
        $warranties = ['in_warranty', 'out_of_warranty', 'extended_warranty'];
        $priorities = ['low', 'normal', 'high', 'urgent'];

        $createdCount = 0;
        $failedCount = 0;

        for ($i = 1; $i <= $count; $i++) {
            // Select random company & options
            $company = $companies[array_rand($companies)];
            $companyId = (int)$company['id'];

            $center = $centers[array_rand($centers)];
            $centerId = (int)$center['id'];

            $location = $cities[array_rand($cities)];
            $firstName = $firstNames[array_rand($firstNames)];
            $lastName = $lastNames[array_rand($lastNames)];
            $phone = '984' . str_pad((string)mt_rand(1000000, 9999999), 7, '0', STR_PAD_LEFT);
            $pincode = $location['pincodes'][array_rand($location['pincodes'])];

            $warranty = $warranties[array_rand($warranties)];
            $priority = $priorities[array_rand($priorities)];

            $companyBrands = $brandsByCompany[$companyId] ?? [];
            $brand = count($companyBrands) > 0 ? $companyBrands[array_rand($companyBrands)] : null;

            $jobType = $jobTypes[array_rand($jobTypes)];
            $category = $categories[array_rand($categories)];
            $symptom = $symptoms[array_rand($symptoms)];

            $purchaseDate = date('Y-m-d', strtotime('-' . mt_rand(1, 36) . ' months'));
            $serialNo = strtoupper(substr($company['code'], 0, 3)) . '-' . mt_rand(100000, 999999);
            $modelNo = strtoupper(substr($category['name'], 0, 2)) . '-' . mt_rand(500, 999) . 'X';
            $companyRef = 'REF-' . strtoupper(substr($company['code'], 0, 3)) . '-' . sprintf('%06d', $i) . '-' . mt_rand(1000, 9999);

            $inchSize = str_contains(strtolower($category['name']), 'tv') ? (string)mt_rand(32, 75) : null;

            $ticketData = [
                'company_id' => $companyId,
                'service_center_id' => $centerId,
                'customer' => [
                    'name' => $firstName . ' ' . $lastName,
                    'phone' => $phone,
                    'alt_phone' => '949' . str_pad((string)mt_rand(1000000, 9999999), 7, '0', STR_PAD_LEFT),
                    'email' => strtolower($firstName . '.' . $lastName . mt_rand(10, 99) . '@example.com'),
                    // The column is address_line1. Sending 'address' wrote
                    // nothing at all, and the desk opened every seeded ticket
                    // on a customer with no address to visit.
                    'address_line1' => mt_rand(10, 99) . ', Main Street',
                    'city' => $location['city'],
                    'pincode' => $pincode,
                    'district_id' => $districtIds[$location['code']],
                ],
                'company_ticket_ref' => $companyRef,
                'job_type_id' => $jobType['id'],
                'product_category_id' => $category['id'],
                'brand_id' => $brand ? $brand['id'] : null,
                'symptom_id' => $symptom['id'],
                'warranty_scope' => $warranty,
                'priority' => $priority,
                'model_no' => $modelNo,
                'serial_no' => $serialNo,
                'purchase_date' => $purchaseDate,
                'size_inch' => $inchSize,
                // reported_issue, not reported_fault — intake ignores keys it
                // does not know, so every seeded ticket arrived with a blank
                // complaint and the symptom as the only clue to the fault.
                'reported_issue' => 'Customer reported: ' . $symptom['name'] . ' during normal operation.',
            ];

            $res = null;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $res = $workflow->intake($ticketData);
                if ($res['ok'] === true) {
                    break;
                }
            }

            if ($res['ok'] === false) {
                $failedCount++;
                $io->warning(sprintf('Ticket %d intake failed: %s', $i, json_encode($res['errors'])));
                continue;
            }

            $ticketId = $res['ticket_id'];
            $createdCount++;

            // Target lifecycle status
            // Distribute: 15% received, 25% assigned, 25% in_progress, 15% on_hold, 20% closed
            $randStatus = mt_rand(1, 100);
            $targetStatus = $randStatus <= 15 ? 'received' : ($randStatus <= 40 ? 'assigned' : ($randStatus <= 65 ? 'in_progress' : ($randStatus <= 80 ? 'on_hold' : 'closed')));

            if ($targetStatus === 'received') {
                continue;
            }

            // Assign Technician
            $tech = $technicians[array_rand($technicians)];
            $assignRes = $workflow->assign($ticketId, (int)$tech['id'], 1, true);

            if ($targetStatus === 'assigned') {
                continue;
            }

            // Record contact & check-in for in_progress / on_hold / closed
            $workflow->recordContact($ticketId, 1, 'Contacted customer to confirm visit schedule.');

            // Add spare part if repair job
            $companySpares = $sparesByCompany[$companyId] ?? [];
            $usedSpare = null;
            if (count($companySpares) > 0 && mt_rand(1, 100) <= 70) {
                $sparePart = $companySpares[array_rand($companySpares)];
                $consumeRes = $spareService->consumeOnTicket($ticketId, [
                    'spare_part_id' => $sparePart['id'],
                    'quantity' => 1,
                    'serial_no' => 'SP-' . mt_rand(100000, 999999),
                    'margin_pct' => '12.50',
                    'is_defective_return' => ($warranty === 'in_warranty'),
                    'notes' => 'Fitted replacement ' . $sparePart['name'],
                ], 1);

                if ($consumeRes['ok'] === true) {
                    $usedSpare = $sparePart;
                }
            }

            if ($targetStatus === 'in_progress') {
                continue;
            }

            if ($targetStatus === 'on_hold') {
                $holdReasons = $this->fetchTable('HoldReasons')->find()->disableHydration()->all()->toList();
                $holdReasonId = count($holdReasons) > 0 ? (int)$holdReasons[0]['id'] : 1;
                $workflow->startHold($ticketId, $holdReasonId, 1, 'Customer requested appointment reschedule to weekend.');
                continue;
            }

            if ($targetStatus === 'closed') {
                // Ensure at least 1 spare if resolution requires spare
                if ($usedSpare === null && count($companySpares) > 0) {
                    $sparePart = $companySpares[array_rand($companySpares)];
                    $spareService->consumeOnTicket($ticketId, [
                        'spare_part_id' => $sparePart['id'],
                        'quantity' => 1,
                        'serial_no' => 'SP-' . mt_rand(100000, 999999),
                        'margin_pct' => '12.50',
                        'is_defective_return' => ($warranty === 'in_warranty'),
                    ], 1);
                }

                // Pick resolution
                $resolution = $resolutions[0]; // Spare part replaced or default
                foreach ($resolutions as $r) {
                    if ($r['requires_spare'] && $usedSpare !== null) {
                        $resolution = $r;
                        break;
                    }
                }

                $closureService->close($ticketId, [
                    'resolution_id' => $resolution['id'],
                    'diagnosis' => 'Defective component identified and tested.',
                    'closure_notes' => 'Job completed successfully. Appliance verified operational.',
                ], 1);
            }
        }

        $io->out(sprintf('<success>Successfully generated %d tickets! (Failed: %d)</success>', $createdCount, $failedCount));

        return static::CODE_SUCCESS;
    }

    private function ensureCompanies(): array
    {
        $table = $this->fetchTable('Companies');
        $desired = [
            ['code' => 'DIANORA', 'name' => 'Dianora Electronics India'],
            ['code' => 'SAMSUNG', 'name' => 'Samsung India Electronics'],
            ['code' => 'LG', 'name' => 'LG Electronics India'],
            ['code' => 'WHIRLPOOL', 'name' => 'Whirlpool of India Ltd'],
            ['code' => 'SONY', 'name' => 'Sony India Pvt Ltd'],
        ];

        foreach ($desired as $d) {
            $row = $table->find()->where(['code' => $d['code']])->first();
            if (!$row) {
                $entity = $table->newEntity([
                    'code' => $d['code'],
                    'name' => $d['name'],
                    'is_active' => true,
                ]);
                $table->save($entity);
            }
        }

        $all = $table->find()->where(['is_active' => true])->disableHydration()->all()->toList();
        $settings = $this->fetchTable('CompanySettings');

        foreach ($all as $c) {
            $prefix = strtoupper(substr((string)$c['code'], 0, 3));
            $existing = $settings->find()->where(['company_id' => $c['id'], 'setting_key' => 'ticket.number_prefix'])->first();
            if (!$existing) {
                $s = $settings->newEntity([
                    'company_id' => $c['id'],
                    'setting_key' => 'ticket.number_prefix',
                    'setting_value' => json_encode($prefix),
                ]);
                $settings->save($s);
            }
        }

        return $all;
    }

    private function ensureServiceCenters(): array
    {
        $table = $this->fetchTable('ServiceCenters');
        $desired = [
            ['code' => 'SC-EKM', 'name' => 'Ernakulam Central Hub'],
            ['code' => 'SC-CLT', 'name' => 'Kozhikode Service Center'],
            ['code' => 'SC-TVM', 'name' => 'Trivandrum South Center'],
        ];

        foreach ($desired as $d) {
            $row = $table->find()->where(['code' => $d['code']])->first();
            if (!$row) {
                $entity = $table->newEntity([
                    'code' => $d['code'],
                    'name' => $d['name'],
                    'is_active' => true,
                ]);
                $table->save($entity);
            }
        }

        return $table->find()->where(['is_active' => true])->disableHydration()->all()->toList();
    }

    private function ensureTechnicians(array $centers): array
    {
        $table = $this->fetchTable('Technicians');
        $centerId = $centers[0]['id'];

        $desired = [
            ['code' => 'TECH-101', 'name' => 'Rajesh Kumar', 'phone' => '9847012345'],
            ['code' => 'TECH-102', 'name' => 'Anish Sharma', 'phone' => '9847023456'],
            ['code' => 'TECH-103', 'name' => 'Suresh Babu', 'phone' => '9847034567'],
            ['code' => 'TECH-104', 'name' => 'Vikram Patel', 'phone' => '9847045678'],
            ['code' => 'TECH-105', 'name' => 'Manoj Verma', 'phone' => '9847056789'],
            ['code' => 'TECH-106', 'name' => 'Deepak Nair', 'phone' => '9847067890'],
        ];

        foreach ($desired as $d) {
            $row = $table->find()->where(['code' => $d['code']])->first();
            if (!$row) {
                $entity = $table->newEntity([
                    'code' => $d['code'],
                    'name' => $d['name'],
                    'phone' => $d['phone'],
                    'service_center_id' => $centerId,
                    'is_active' => true,
                ]);
                $table->save($entity);
            }
        }

        return $table->find()->where(['is_active' => true])->disableHydration()->all()->toList();
    }

    private function ensureJobTypes(): array
    {
        return $this->fetchTable('JobTypes')->find()->disableHydration()->all()->toList();
    }

    private function ensureCategories(): array
    {
        return $this->fetchTable('ProductCategories')->find()->disableHydration()->all()->toList();
    }

    /**
     * The master districts keyed by code, so the seed can name a district
     * rather than guess at its id.
     *
     * @return array<string, int>
     */
    private function districtIdsByCode(): array
    {
        $ids = [];
        foreach ($this->fetchTable('Districts')->find()->all() as $district) {
            $ids[(string)$district->code] = (int)$district->id;
        }

        return $ids;
    }

    private function ensureSymptoms(): array
    {
        return $this->fetchTable('Symptoms')->find()->disableHydration()->all()->toList();
    }

    private function ensureResolutions(): array
    {
        return $this->fetchTable('Resolutions')->find()->disableHydration()->all()->toList();
    }

    private function ensureBrands(array $companies): array
    {
        $table = $this->fetchTable('Brands');
        $byCompany = [];

        foreach ($companies as $c) {
            $cId = (int)$c['id'];
            $brands = $table->find()->where(['company_id' => $cId])->disableHydration()->all()->toList();
            if (count($brands) === 0) {
                $b1 = $table->newEntity(['company_id' => $cId, 'code' => strtoupper($c['code']) . '-STD', 'name' => $c['name'] . ' Standard']);
                $table->save($b1);
                $brands = $table->find()->where(['company_id' => $cId])->disableHydration()->all()->toList();
            }
            $byCompany[$cId] = $brands;
        }

        return $byCompany;
    }

    private function ensureSpares(array $companies): array
    {
        $table = $this->fetchTable('SpareParts');
        $byCompany = [];

        $sparesCatalog = [
            ['part_no' => 'MAIN-BOARD-01', 'name' => 'Main Logic Controller Board', 'cost' => 2400, 'mrp' => 3500],
            ['part_no' => 'POWER-PSU-02', 'name' => 'Power Supply Unit (SMPS)', 'cost' => 1800, 'mrp' => 2600],
            ['part_no' => 'DISPLAY-PANEL-55', 'name' => '55" Ultra HD Display Panel', 'cost' => 8500, 'mrp' => 12500],
            ['part_no' => 'MOTOR-INV-04', 'name' => 'Direct Drive Inverter Motor', 'cost' => 3200, 'mrp' => 4800],
            ['part_no' => 'COMPRESSOR-RELAY-05', 'name' => 'Compressor Start Relay & OLP', 'cost' => 650, 'mrp' => 1100],
            ['part_no' => 'DRAIN-PUMP-06', 'name' => 'Water Drain Pump Assembly', 'cost' => 850, 'mrp' => 1400],
        ];

        foreach ($companies as $c) {
            $cId = (int)$c['id'];
            $spares = $table->find()->where(['company_id' => $cId])->disableHydration()->all()->toList();
            if (count($spares) === 0) {
                foreach ($sparesCatalog as $sp) {
                    $entity = $table->newEntity([
                        'company_id' => $cId,
                        'part_no' => strtoupper($c['code']) . '-' . $sp['part_no'],
                        'name' => $sp['name'],
                        'cost_paise' => $sp['cost'] * 100,
                        'mrp_paise' => $sp['mrp'] * 100,
                        'is_active' => true,
                    ]);
                    $table->save($entity);
                }
                $spares = $table->find()->where(['company_id' => $cId])->disableHydration()->all()->toList();
            }
            $byCompany[$cId] = $spares;
        }

        return $byCompany;
    }

    /**
     * Park each company's counter past every number it has already issued
     * this period, so the run's intakes do not collide with what is there.
     *
     * The value has to come from the ticket numbers themselves. Deriving it
     * from `MAX(tickets.id)` — as this used to — is unrelated arithmetic:
     * once a hundred-odd tickets existed it produced a counter well below
     * the numbers already in use, and every intake in the run died on the
     * unique index with `ticket_no: The provided value is invalid`.
     *
     * GREATEST rather than a delete-and-insert: a sequence that has run
     * ahead of the saved tickets — an allocation whose intake then failed —
     * is ahead for a reason, and pulling it back would collide again.
     */
    private function syncSequences(): void
    {
        $conn = $this->fetchTable('TicketSequences')->getConnection();
        $period = date('Ym');

        foreach ($this->fetchTable('Companies')->find()->all() as $company) {
            $cId = (int)$company->id;

            // The counter is the last dash-separated segment of the number,
            // whatever prefix the company's settings gave it.
            $row = $conn->execute(
                "SELECT MAX(CAST(SUBSTRING_INDEX(ticket_no, '-', -1) AS UNSIGNED)) AS max_seq
                   FROM tickets
                  WHERE company_id = :cId AND ticket_no LIKE :pattern",
                ['cId' => $cId, 'pattern' => '%-' . $period . '-%'],
            )->fetch('assoc');

            $lastValue = (int)($row['max_seq'] ?? 0);

            $conn->execute(
                'INSERT INTO ticket_sequences (company_id, period, `last_value`, created, modified)
                     VALUES (:cId, :period, :lastValue, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                     `last_value` = GREATEST(`last_value`, :lastValueUpdate),
                     modified = NOW()',
                ['cId' => $cId, 'period' => $period, 'lastValue' => $lastValue, 'lastValueUpdate' => $lastValue],
            );
        }
    }
}
