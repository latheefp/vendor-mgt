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
}
