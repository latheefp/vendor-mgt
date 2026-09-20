<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Test case for Settings API controllers.
 */
class SettingsControllersTest extends TestCase
{
    use IntegrationTestTrait;

    public function testUsersList(): void
    {
        $this->get('/api/users');
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');
    }

    public function testRolesList(): void
    {
        $this->get('/api/roles');
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');
    }

    public function testPermissionsCatalog(): void
    {
        $this->get('/api/roles/permissions-catalog');
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');
    }

    public function testProductsCategories(): void
    {
        $this->get('/api/product-categories');
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');
    }

    public function testProductsList(): void
    {
        $this->get('/api/products');
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');
    }

    public function testMasterListsIndex(): void
    {
        $this->get('/api/master-lists');
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');
    }

    public function testCompaniesList(): void
    {
        $this->get('/api/companies');
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');
    }

    public function testAddRateCardItemWithoutLabel(): void
    {
        $companies = \Cake\ORM\TableRegistry::getTableLocator()->get('Companies');
        $company = $companies->find()->first();
        if ($company === null) {
            $company = $companies->newEntity(['code' => 'TEST', 'name' => 'Test Company', 'is_active' => true]);
            $companies->saveOrFail($company);
        }

        $jobTypes = \Cake\ORM\TableRegistry::getTableLocator()->get('JobTypes');
        $jobType = $jobTypes->find()->first();
        if ($jobType === null) {
            $jobType = $jobTypes->newEntity(['code' => 'service', 'name' => 'Service', 'is_active' => true]);
            $jobTypes->saveOrFail($jobType);
        }

        $cards = \Cake\ORM\TableRegistry::getTableLocator()->get('RateCards');
        $card = $cards->newEntity([
            'company_id' => $company->id,
            'name' => 'Draft Card Test',
            'version' => 99,
            'status' => 'draft',
            'effective_from' => date('Y-m-d'),
            'currency' => 'INR',
        ]);
        $cards->saveOrFail($card);

        $authoring = new \App\Service\RateCardAuthoring();
        $itemId = $authoring->addItem((int)$card->id, [
            'job_type_id' => $jobType->id,
            'warranty_scope' => 'in_warranty',
            'amount_paise' => 40000,
            'payer' => 'company',
        ]);
        $this->assertGreaterThan(0, $itemId);
    }

    public function testDeleteRateCardRemovesDraftAndItsItems(): void
    {
        $companies = \Cake\ORM\TableRegistry::getTableLocator()->get('Companies');
        $company = $companies->find()->first();
        if ($company === null) {
            $company = $companies->newEntity(['code' => 'TEST', 'name' => 'Test Company', 'is_active' => true]);
            $companies->saveOrFail($company);
        }

        $jobTypes = \Cake\ORM\TableRegistry::getTableLocator()->get('JobTypes');
        $jobType = $jobTypes->find()->first();
        if ($jobType === null) {
            $jobType = $jobTypes->newEntity(['code' => 'service', 'name' => 'Service', 'is_active' => true]);
            $jobTypes->saveOrFail($jobType);
        }

        $cards = \Cake\ORM\TableRegistry::getTableLocator()->get('RateCards');
        $card = $cards->newEntity([
            'company_id' => $company->id,
            'name' => 'Draft To Delete',
            'version' => 98,
            'status' => 'draft',
            'effective_from' => date('Y-m-d'),
            'currency' => 'INR',
        ]);
        $cards->saveOrFail($card);

        $authoring = new \App\Service\RateCardAuthoring();
        $itemId = $authoring->addItem((int)$card->id, [
            'job_type_id' => $jobType->id,
            'warranty_scope' => 'in_warranty',
            'amount_paise' => 40000,
            'payer' => 'company',
        ]);

        $this->enableCsrfToken('gvsCsrfToken');
        $this->delete(sprintf('/api/companies/%d/rate-cards/%d', $company->id, $card->id));
        $this->assertResponseOk();

        $this->assertNull($cards->find()->where(['id' => $card->id])->first());
        $items = \Cake\ORM\TableRegistry::getTableLocator()->get('RateCardItems');
        $this->assertNull($items->find()->where(['id' => $itemId])->first());
    }

    public function testDeleteRateCardRefusesPublishedCard(): void
    {
        $companies = \Cake\ORM\TableRegistry::getTableLocator()->get('Companies');
        $company = $companies->find()->first();
        if ($company === null) {
            $company = $companies->newEntity(['code' => 'TEST', 'name' => 'Test Company', 'is_active' => true]);
            $companies->saveOrFail($company);
        }

        $cards = \Cake\ORM\TableRegistry::getTableLocator()->get('RateCards');
        $card = $cards->newEntity([
            'company_id' => $company->id,
            'name' => 'Published Card',
            'version' => 97,
            'status' => 'active',
            'effective_from' => date('Y-m-d'),
            'currency' => 'INR',
        ]);
        $cards->saveOrFail($card);

        $this->enableCsrfToken('gvsCsrfToken');
        $this->delete(sprintf('/api/companies/%d/rate-cards/%d', $company->id, $card->id));
        $this->assertResponseError();

        $this->assertNotNull($cards->find()->where(['id' => $card->id])->first());
    }

    public function testImportRateCardItemsSkipsBadRowsAndSavesGoodOnes(): void
    {
        $companies = \Cake\ORM\TableRegistry::getTableLocator()->get('Companies');
        $company = $companies->find()->first();
        if ($company === null) {
            $company = $companies->newEntity(['code' => 'TEST', 'name' => 'Test Company', 'is_active' => true]);
            $companies->saveOrFail($company);
        }

        $jobTypes = \Cake\ORM\TableRegistry::getTableLocator()->get('JobTypes');
        $jobType = $jobTypes->find()->where(['company_id IS' => null])->first();
        if ($jobType === null) {
            $jobType = $jobTypes->newEntity(['code' => 'service', 'name' => 'Service', 'is_active' => true]);
            $jobTypes->saveOrFail($jobType);
        }

        $cards = \Cake\ORM\TableRegistry::getTableLocator()->get('RateCards');
        $card = $cards->newEntity([
            'company_id' => $company->id,
            'name' => 'Import Test Card',
            'version' => 199,
            'status' => 'draft',
            'effective_from' => date('Y-m-d'),
            'currency' => 'INR',
        ]);
        $cards->saveOrFail($card);

        $authoring = new \App\Service\RateCardAuthoring();
        $result = $authoring->importItems((int)$card->id, [
            [
                'job_type_code' => (string)$jobType->code,
                'product_category_code' => '',
                'warranty_scope' => 'in_warranty',
                'label' => 'CSV Row',
                'size_min_inch' => '',
                'size_max_inch' => '',
                'amount_rupees' => '500.00',
                'payer' => 'company',
            ],
            [
                'job_type_code' => 'does_not_exist',
                'product_category_code' => '',
                'warranty_scope' => 'in_warranty',
                'label' => 'Bad Row',
                'size_min_inch' => '',
                'size_max_inch' => '',
                'amount_rupees' => '500.00',
                'payer' => 'company',
            ],
            [
                'job_type_code' => (string)$jobType->code,
                'product_category_code' => '',
                'warranty_scope' => 'in_warranty',
                'label' => 'Bad Amount Row',
                'size_min_inch' => '',
                'size_max_inch' => '',
                'amount_rupees' => 'not-a-number',
                'payer' => 'company',
            ],
        ]);

        $this->assertCount(1, $result['created']);
        $this->assertCount(2, $result['errors']);
        $this->assertSame(3, $result['errors'][0]['row']);
        $this->assertFalse($result['errors'][0]['duplicate']);
        $this->assertSame(4, $result['errors'][1]['row']);
        $this->assertFalse($result['errors'][1]['duplicate']);
    }

    public function testImportRateCardItemsSkipsDuplicatesInFileAndAgainstExistingLines(): void
    {
        $companies = \Cake\ORM\TableRegistry::getTableLocator()->get('Companies');
        $company = $companies->find()->first();
        if ($company === null) {
            $company = $companies->newEntity(['code' => 'TEST', 'name' => 'Test Company', 'is_active' => true]);
            $companies->saveOrFail($company);
        }

        $jobTypes = \Cake\ORM\TableRegistry::getTableLocator()->get('JobTypes');
        $jobType = $jobTypes->find()->where(['company_id IS' => null])->first();
        if ($jobType === null) {
            $jobType = $jobTypes->newEntity(['code' => 'service', 'name' => 'Service', 'is_active' => true]);
            $jobTypes->saveOrFail($jobType);
        }

        $cards = \Cake\ORM\TableRegistry::getTableLocator()->get('RateCards');
        $card = $cards->newEntity([
            'company_id' => $company->id,
            'name' => 'Duplicate Test Card',
            'version' => 399,
            'status' => 'draft',
            'effective_from' => date('Y-m-d'),
            'currency' => 'INR',
        ]);
        $cards->saveOrFail($card);

        $authoring = new \App\Service\RateCardAuthoring();

        // Already on the card before any CSV is touched.
        $authoring->addItem((int)$card->id, [
            'job_type_id' => $jobType->id,
            'warranty_scope' => 'in_warranty',
            'amount_paise' => 40000,
            'payer' => 'company',
        ]);

        $row = [
            'job_type_code' => (string)$jobType->code,
            'product_category_code' => '',
            'warranty_scope' => 'in_warranty',
            'label' => 'CSV Row',
            'size_min_inch' => '',
            'size_max_inch' => '',
            'amount_rupees' => '500.00',
            'payer' => 'company',
        ];

        // Row 1 collides with the line added above; row 2 collides with row 1.
        $result = $authoring->importItems((int)$card->id, [$row, $row]);

        $this->assertSame([], $result['created']);
        $this->assertCount(2, $result['errors']);
        $this->assertTrue($result['errors'][0]['duplicate']);
        $this->assertTrue($result['errors'][1]['duplicate']);
    }

    public function testMergeDuplicateItemsCollapsesAgreeingLinesAndFlagsConflicts(): void
    {
        $companies = \Cake\ORM\TableRegistry::getTableLocator()->get('Companies');
        $company = $companies->find()->first();
        if ($company === null) {
            $company = $companies->newEntity(['code' => 'TEST', 'name' => 'Test Company', 'is_active' => true]);
            $companies->saveOrFail($company);
        }

        $jobTypes = \Cake\ORM\TableRegistry::getTableLocator()->get('JobTypes');
        $jobType = $jobTypes->find()->where(['company_id IS' => null])->first();
        if ($jobType === null) {
            $jobType = $jobTypes->newEntity(['code' => 'service', 'name' => 'Service', 'is_active' => true]);
            $jobTypes->saveOrFail($jobType);
        }

        $categories = \Cake\ORM\TableRegistry::getTableLocator()->get('ProductCategories');
        $category = $categories->find()->where(['company_id IS' => null])->first();
        if ($category === null) {
            $category = $categories->newEntity(['code' => 'led_tv', 'name' => 'LED TV', 'is_active' => true]);
            $categories->saveOrFail($category);
        }

        $cards = \Cake\ORM\TableRegistry::getTableLocator()->get('RateCards');
        $card = $cards->newEntity([
            'company_id' => $company->id,
            'name' => 'Merge Test Card',
            'version' => 499,
            'status' => 'draft',
            'effective_from' => date('Y-m-d'),
            'currency' => 'INR',
        ]);
        $cards->saveOrFail($card);

        $authoring = new \App\Service\RateCardAuthoring();

        // Agreeing pair: same job type, scope, size band, amount and
        // payer — differ only by category. Mergeable.
        $authoring->addItem((int)$card->id, [
            'job_type_id' => $jobType->id,
            'product_category_id' => null,
            'warranty_scope' => 'in_warranty',
            'size_min_inch' => '24.00',
            'size_max_inch' => '43.00',
            'amount_paise' => 40000,
            'payer' => 'company',
        ]);
        $authoring->addItem((int)$card->id, [
            'job_type_id' => $jobType->id,
            'product_category_id' => $category->id,
            'warranty_scope' => 'in_warranty',
            'size_min_inch' => '24.00',
            'size_max_inch' => '43.00',
            'amount_paise' => 40000,
            'payer' => 'company',
        ]);

        // Conflicting pair: same key, but a different amount — must not
        // be silently collapsed.
        $authoring->addItem((int)$card->id, [
            'job_type_id' => $jobType->id,
            'product_category_id' => null,
            'warranty_scope' => 'out_of_warranty',
            'amount_paise' => 50000,
            'payer' => 'customer',
        ]);
        $authoring->addItem((int)$card->id, [
            'job_type_id' => $jobType->id,
            'product_category_id' => $category->id,
            'warranty_scope' => 'out_of_warranty',
            'amount_paise' => 99900,
            'payer' => 'customer',
        ]);

        $result = $authoring->mergeDuplicateItems((int)$card->id);

        $this->assertSame(1, $result['merged_groups']);
        $this->assertCount(1, $result['removed']);
        $this->assertCount(1, $result['conflicts']);
        $this->assertCount(2, $result['conflicts'][0]['item_ids']);

        $remaining = \Cake\ORM\TableRegistry::getTableLocator()->get('RateCardItems')->find()
            ->where(['rate_card_id' => $card->id, 'is_active' => true])
            ->count();
        // Started with 4, one merged pair loses one row, the conflicting
        // pair keeps both.
        $this->assertSame(3, $remaining);

        // The surviving line from the merged pair is the category-specific
        // one, not the null "applies to everything" one.
        $survivor = \Cake\ORM\TableRegistry::getTableLocator()->get('RateCardItems')->find()
            ->where(['rate_card_id' => $card->id, 'warranty_scope' => 'in_warranty'])
            ->first();
        $this->assertSame((int)$category->id, (int)$survivor->product_category_id);
    }

    public function testImportRateCardItemsEndpoint(): void
    {
        $companies = \Cake\ORM\TableRegistry::getTableLocator()->get('Companies');
        $company = $companies->find()->first();
        if ($company === null) {
            $company = $companies->newEntity(['code' => 'TEST', 'name' => 'Test Company', 'is_active' => true]);
            $companies->saveOrFail($company);
        }

        $jobTypes = \Cake\ORM\TableRegistry::getTableLocator()->get('JobTypes');
        $jobType = $jobTypes->find()->where(['company_id IS' => null])->first();
        if ($jobType === null) {
            $jobType = $jobTypes->newEntity(['code' => 'service', 'name' => 'Service', 'is_active' => true]);
            $jobTypes->saveOrFail($jobType);
        }

        $cards = \Cake\ORM\TableRegistry::getTableLocator()->get('RateCards');
        $card = $cards->newEntity([
            'company_id' => $company->id,
            'name' => 'Import Endpoint Test Card',
            'version' => 299,
            'status' => 'draft',
            'effective_from' => date('Y-m-d'),
            'currency' => 'INR',
        ]);
        $cards->saveOrFail($card);

        $this->get(sprintf('/api/companies/%d/rate-cards/%d/items/template', $company->id, $card->id));
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $templateCsv = (string)$this->_response->getBody();
        $this->assertStringContainsString('job_type_code,product_category_code', $templateCsv);
        $this->assertStringContainsString((string)$jobType->code, $templateCsv);

        $csv = "job_type_code,product_category_code,warranty_scope,label,size_min_inch,size_max_inch,amount_rupees,payer\n"
            . sprintf('%s,,in_warranty,Uploaded Row,,,750.00,company', $jobType->code) . "\n";

        $tmpFile = tempnam(sys_get_temp_dir(), 'rate-card-import');
        file_put_contents($tmpFile, $csv);

        $this->enableCsrfToken('gvsCsrfToken');
        $this->configRequest([
            'files' => [
                'file' => [
                    'tmp_name' => $tmpFile,
                    'error' => UPLOAD_ERR_OK,
                    'name' => 'items.csv',
                    'type' => 'text/csv',
                    'size' => strlen($csv),
                ],
            ],
        ]);

        $this->post(sprintf('/api/companies/%d/rate-cards/%d/items/import', $company->id, $card->id));

        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(1, $body['data']['created_count']);
        $this->assertSame(0, $body['data']['duplicate_count']);
        $this->assertSame([], $body['data']['errors']);

        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }
    }
}
