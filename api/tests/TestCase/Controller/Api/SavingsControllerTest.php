<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Routing and the shape of the two read endpoints. The ledger's own
 * arithmetic is covered by SavingsServiceTest.
 */
class SavingsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testBalancesReturnsAList(): void
    {
        $this->get('/api/service-centers/savings');

        $this->assertResponseOk();
        $this->assertResponseContains('"data"');
    }

    /**
     * A centre with no history at all still answers with a zero balance
     * rather than an error — there is nothing invalid about a branch that
     * has never had a cash movement recorded yet.
     */
    public function testBalanceForUnknownCenterIsZeroNotAnError(): void
    {
        $this->get('/api/service-centers/999999/savings');

        $this->assertResponseOk();
        $this->assertResponseContains('"paise":0');
        $this->assertResponseContains('"ledger":[]');
    }

    /**
     * A correction requires a reason and a real sign, and — like every
     * other write on this ledger — a signed-in caller.
     */
    public function testAdjustRequiresAuthentication(): void
    {
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);

        $this->post('/api/service-centers/1/savings/adjust', [
            'amount' => '100',
            'description' => 'Testing the route resolves.',
        ]);

        $this->assertResponseError();
        $this->assertResponseNotContains('Controller class Savings could not be found');
    }
}
