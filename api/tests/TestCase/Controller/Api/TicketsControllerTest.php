<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Service\CompanyConfigRepository;
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

    public function testRemoveChargeLine(): void
    {
        $this->enableCsrfToken('gvsCsrfToken');
        $this->delete('/api/tickets/1/charges/99999');
        $this->assertResponseCode(422);
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

    public function testEditTicket(): void
    {
        $this->enableCsrfToken('gvsCsrfToken');
        $this->configRequest([
            'headers' => ['Accept' => 'application/json'],
        ]);

        $companies = $this->getTableLocator()->get('Companies');
        $company = $companies->newEntity([
            'code' => 'TEST_COMP_' . rand(1000, 9999),
            'name' => 'Test Company',
            'is_active' => true,
        ]);
        $companies->saveOrFail($company);

        $scs = $this->getTableLocator()->get('ServiceCenters');
        $sc = $scs->newEntity([
            'code' => 'TEST_SC_' . rand(1000, 9999),
            'name' => 'Center 1',
            'is_active' => true,
        ]);
        $scs->saveOrFail($sc);

        $jobTypes = $this->getTableLocator()->get('JobTypes');
        $jobType = $jobTypes->newEntity([
            'code' => 'TEST_JT_' . rand(1000, 9999),
            'name' => 'Demo',
            'is_active' => true,
        ]);
        $jobTypes->saveOrFail($jobType);

        $createPayload = [
            'company_id' => $company->id,
            'service_center_id' => $sc->id,
            'job_type_id' => $jobType->id,
            'warranty_scope' => 'in_warranty',
            'reported_issue' => 'Original reported issue',
            'priority' => 'normal',
            'serial_no' => 'SN12345678',
            'purchase_date' => '2025-01-01',
            'customer' => [
                'name' => 'John Doe',
                'phone' => '9876543210',
                'address_line1' => '123 St',
                'city' => 'Kochi',
            ],
        ];

        $result = (new TicketWorkflow())->intake($createPayload);
        $this->assertTrue($result['ok']);
        $ticketId = $result['ticket_id'];

        $updatePayload = $createPayload;
        $updatePayload['reported_issue'] = 'Updated reported issue text';

        $this->put('/api/tickets/' . $ticketId, $updatePayload);
        $this->assertResponseOk();
        $this->assertResponseContains('Updated reported issue text');
    }

    /**
     * Intake with no `service_center_id` at all used to be a flat
     * rejection. A company that has configured
     * `ticket.default_service_center_code` gets that centre instead — the
     * desk's own form already pre-fills the same default, so this only
     * matters to a caller (an import, a future API client) that sends
     * nothing for the field.
     */
    public function testIntakeUsesCompanyDefaultServiceCenterWhenOmitted(): void
    {
        $companies = $this->getTableLocator()->get('Companies');
        $company = $companies->newEntity([
            'code' => 'TEST_COMP_' . rand(1000, 9999),
            'name' => 'Test Company',
            'is_active' => true,
        ]);
        $companies->saveOrFail($company);

        $scs = $this->getTableLocator()->get('ServiceCenters');
        $sc = $scs->newEntity([
            'code' => 'TEST_SC_' . rand(1000, 9999),
            'name' => 'Default Center',
            'is_active' => true,
        ]);
        $scs->saveOrFail($sc);

        $jobTypes = $this->getTableLocator()->get('JobTypes');
        $jobType = $jobTypes->newEntity([
            'code' => 'TEST_JT_' . rand(1000, 9999),
            'name' => 'Demo',
            'is_active' => true,
        ]);
        $jobTypes->saveOrFail($jobType);

        // Through the repository, not a raw table save: it caches settings
        // per company_id in Redis, and only its own write path invalidates
        // that cache. A raw save here left a stale empty entry in place
        // whenever a fixture-truncated test DB handed this test a
        // company_id an earlier test had already cached.
        $config = new CompanyConfigRepository();
        $config->putSetting((int)$company->id, 'ticket.default_service_center_code', $sc->code);

        // Every company left on the shared default "TKT" prefix collides
        // the instant two of them are each other's first ticket in the
        // same calendar month — this test's own ticket_no would otherwise
        // depend on which other tests already ran first.
        $config->putSetting((int)$company->id, 'ticket.number_prefix', 'TSC' . rand(1000, 9999));

        $payload = [
            'company_id' => $company->id,
            'job_type_id' => $jobType->id,
            'warranty_scope' => 'in_warranty',
            'reported_issue' => 'No branch picked at intake',
            'priority' => 'normal',
            'serial_no' => 'SN12345678',
            'purchase_date' => '2025-01-01',
            'customer' => [
                'name' => 'Jane Doe',
                'phone' => '9876543211',
                'address_line1' => '123 St',
                'city' => 'Kochi',
            ],
        ];

        $result = (new TicketWorkflow())->intake($payload);
        $this->assertTrue($result['ok']);

        $ticket = $this->getTableLocator()->get('Tickets')->get($result['ticket_id']);
        $this->assertSame($sc->id, $ticket->service_center_id);
    }

    /**
     * A company with no default configured still refuses intake that
     * names no service centre at all — the fallback only fires when
     * there's something to fall back to.
     */
    public function testIntakeStillRejectsMissingServiceCenterWithNoDefaultConfigured(): void
    {
        $companies = $this->getTableLocator()->get('Companies');
        $company = $companies->newEntity([
            'code' => 'TEST_COMP_' . rand(1000, 9999),
            'name' => 'Test Company',
            'is_active' => true,
        ]);
        $companies->saveOrFail($company);

        $jobTypes = $this->getTableLocator()->get('JobTypes');
        $jobType = $jobTypes->newEntity([
            'code' => 'TEST_JT_' . rand(1000, 9999),
            'name' => 'Demo',
            'is_active' => true,
        ]);
        $jobTypes->saveOrFail($jobType);

        $payload = [
            'company_id' => $company->id,
            'job_type_id' => $jobType->id,
            'warranty_scope' => 'in_warranty',
            'reported_issue' => 'No branch picked, no default set',
            'priority' => 'normal',
            'serial_no' => 'SN12345678',
            'purchase_date' => '2025-01-01',
            'customer' => [
                'name' => 'Jane Doe',
                'phone' => '9876543212',
                'address_line1' => '123 St',
                'city' => 'Kochi',
            ],
        ];

        $result = (new TicketWorkflow())->intake($payload);
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('service_center_id', $result['errors']);
    }
}
