<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Every wallet action is self-scoped to whoever is signed in — there is
 * no technician id anywhere in these routes — so what matters here is
 * that a caller with no session gets refused, not a 404 that would mean
 * the route itself is missing.
 */
class WalletControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testMeRequiresAuthentication(): void
    {
        $this->get('/api/wallet/me');

        $this->assertResponseError();
        $this->assertResponseNotContains('Controller class Wallet could not be found');
    }

    public function testWithdrawRequiresAuthentication(): void
    {
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);

        $this->post('/api/wallet/me/withdraw');

        $this->assertResponseError();
        $this->assertResponseNotContains('Controller class Wallet could not be found');
    }
}
