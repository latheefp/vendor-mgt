<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Service\TicketWorkflow;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * App\Controller\Api\TicketsController & Features Test Case
 */
class TicketsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testOptions(): void
    {
        $this->get('/api/tickets/options');
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');
    }

    public function testIndex(): void
    {
        $this->get('/api/tickets');
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');
    }

    /**
     * `active` and `inactive` split the list on whether the job still owes
     * work, which is the question the desk opens with. Asserted as a
     * partition — every row on one side, none of it on the other — so a
     * status added to the workflow without a home here shows up as a
     * failure rather than as a job quietly missing from the board.
     */
    public function testIndexActiveFilterExcludesTerminalStatuses(): void
    {
        $this->get('/api/tickets?status=active&limit=100');
        $this->assertResponseOk();

        foreach ($this->ticketStatuses() as $status) {
            $this->assertNotContains($status, TicketWorkflow::TERMINAL_STATUSES);
        }

        $this->get('/api/tickets?status=inactive&limit=100');
        $this->assertResponseOk();

        foreach ($this->ticketStatuses() as $status) {
            $this->assertContains($status, TicketWorkflow::TERMINAL_STATUSES);
        }
    }

    /**
     * @return list<string>
     */
    private function ticketStatuses(): array
    {
        $body = json_decode((string)$this->_response->getBody(), true);

        return array_map(
            static fn (array $ticket): string => (string)$ticket['status'],
            $body['data'] ?? [],
        );
    }

    /**
     * The frozen ledger is readable on its own rather than only as part of
     * the whole ticket. An unknown ticket answers with an empty ledger and
     * zero totals, which is what it genuinely has.
     */
    public function testCharges(): void
    {
        $this->get('/api/tickets/1/charges');
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');
        $this->assertResponseContains('gross_margin');
    }

    public function testComments(): void
    {
        $this->get('/api/tickets/1/comments');
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');
    }

    public function testInvoicesList(): void
    {
        $this->get('/api/invoices');
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');
    }

    public function testPayoutsList(): void
    {
        $this->get('/api/payouts');
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');
    }
}
