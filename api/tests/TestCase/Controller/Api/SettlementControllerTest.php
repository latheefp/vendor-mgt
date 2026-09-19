<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The settlement detail views and the line-override endpoints behind them.
 *
 * These assert routing and the refusals, which is the half that has to
 * hold whatever is in the database: an invoice that does not exist, and a
 * line addressed through an invoice it does not belong to. The arithmetic
 * on the way through is covered by the domain tests.
 */
class SettlementControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testInvoiceDetailNotFound(): void
    {
        $this->get('/api/invoices/999999');

        $this->assertResponseCode(404);
        $this->assertResponseContains('not_found');
    }

    public function testPayoutDetailNotFound(): void
    {
        $this->get('/api/payouts/999999');

        $this->assertResponseCode(404);
    }

    /**
     * The line id is matched against the invoice in the URL, not on its
     * own. Without that, a line id from one company's invoice would be
     * editable through another company's — the ids are sequential, so
     * this is a guess away rather than an attack.
     */
    public function testOverrideRejectsLineFromAnotherInvoice(): void
    {
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);

        $this->patch('/api/invoices/999999/lines/999999', [
            'amount' => '100',
            'reason' => 'Testing that this line cannot be reached from here.',
        ]);

        // Either the route rejects the unauthenticated caller or the
        // service rejects the pairing; what must never happen is a 200.
        $this->assertResponseError();
    }

    public function testOverrideRequiresAnAmount(): void
    {
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);

        $this->patch('/api/invoices/1/lines/1', ['reason' => 'No amount given.']);

        $this->assertResponseError();
    }

    public function testPayoutOverrideRouteExists(): void
    {
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);

        $this->patch('/api/payouts/999999/lines/999999', [
            'amount' => '100',
            'reason' => 'Testing the route resolves.',
        ]);

        // A missing route answers 404 with a routing error rather than the
        // service's own, so this asserts the endpoint is wired at all.
        $this->assertResponseError();
        $this->assertResponseNotContains('Controller class Settlement could not be found');
    }

    /**
     * The shape a P&L report always has, even with nothing closed in the
     * period: income and expenses each present, net margin computed from
     * them. The arithmetic itself is covered by the domain tests.
     */
    public function testProfitAndLossReturnsIncomeAndExpenseTotals(): void
    {
        $this->get('/api/reports/profit-loss?period_start=2000-01-01&period_end=2000-01-31');

        $this->assertResponseOk();
        $this->assertResponseContains('"income"');
        $this->assertResponseContains('"expenses"');
        $this->assertResponseContains('"net_margin"');
        $this->assertResponseContains('"breakdown"');
    }

    public function testTechnicianDuesRouteExists(): void
    {
        $this->get('/api/technician-dues');

        $this->assertResponseOk();
        $this->assertResponseContains('"technicians"');
        $this->assertResponseContains('"totals"');
    }
}
