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
}
