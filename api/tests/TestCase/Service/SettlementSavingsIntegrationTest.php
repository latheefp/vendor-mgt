<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\SavingsService;
use App\Service\SettlementService;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * The two places real cash actually moves, and where that lands on the
 * service centre's own savings ledger.
 *
 * `recordInvoicePayment()` and `markPayoutPaid()` are the entire reason
 * `SavingsService` exists — a ledger nothing ever credits or debits is
 * just an empty table with a nice API on it. These tests exist to catch
 * the day one of those two write paths stops calling it, which the
 * calling method's own tests would never notice: they only assert the
 * invoice or the payout came out right, not that the cash side-effect
 * fired at all.
 */
class SettlementSavingsIntegrationTest extends TestCase
{
    use LocatorAwareTrait;

    private SettlementService $settlement;
    private SavingsService $savings;

    public function setUp(): void
    {
        parent::setUp();

        ConnectionManager::get('test')->begin();

        $this->settlement = new SettlementService();
        $this->savings = new SavingsService();
    }

    public function tearDown(): void
    {
        ConnectionManager::get('test')->rollback();

        parent::tearDown();
    }

    public function testMarkingAPayoutPaidDebitsTheTechniciansOwnCenter(): void
    {
        $centerId = $this->seedCenter('A');
        $technicianId = $this->seedTechnician($centerId);
        $payoutId = $this->seedPayout($technicianId, 150000, 'approved');

        $result = $this->settlement->markPayoutPaid($payoutId, 'bank_transfer', 'UTR123', null);

        $this->assertTrue($result['ok']);
        $this->assertSame(-150000, $this->savings->balance($centerId)->paise);

        $ledger = $this->savings->ledger($centerId);
        $this->assertSame('debit', $ledger[0]['entry_type']);
        $this->assertSame('technician_payout', $ledger[0]['source_type']);
        $this->assertSame($payoutId, $ledger[0]['source_id']);
    }

    /**
     * A payout that has not been approved must not move cash — approval
     * is the control on this ledger, and paying around it would remove
     * it silently rather than refuse it loudly.
     */
    public function testAnUnapprovedPayoutIsRefusedAndMovesNoCash(): void
    {
        $centerId = $this->seedCenter('A');
        $technicianId = $this->seedTechnician($centerId);
        $payoutId = $this->seedPayout($technicianId, 100000, 'draft');

        $result = $this->settlement->markPayoutPaid($payoutId, 'bank_transfer', null, null);

        $this->assertFalse($result['ok']);
        $this->assertTrue($this->savings->balance($centerId)->isZero());
    }

    public function testRecordingAPaymentCreditsTheOneCenterBehindTheInvoice(): void
    {
        $centerId = $this->seedCenter('A');
        $companyId = $this->seedCompany();
        $ticketId = $this->seedTicket($companyId, $centerId);
        $invoiceId = $this->seedInvoice($companyId, [
            ['ticket_id' => $ticketId, 'amount_paise' => 500000],
        ]);

        $result = $this->settlement->recordInvoicePayment($invoiceId, 500000, 'REF1', null);

        $this->assertSame('paid', $result['status']);
        $this->assertSame(500000, $this->savings->balance($centerId)->paise);
    }

    /**
     * The case the whole split exists for: one invoice, two branches'
     * tickets behind it, one payment. Each centre gets its share of the
     * cash, not the whole thing and not none of it.
     */
    public function testAPaymentSplitsAcrossCentersByEachOnesShareOfTheInvoice(): void
    {
        $centerA = $this->seedCenter('A');
        $centerB = $this->seedCenter('B');
        $companyId = $this->seedCompany();
        $ticketA = $this->seedTicket($companyId, $centerA);
        $ticketB = $this->seedTicket($companyId, $centerB);

        // 300 : 700 of the billed work, so a part payment of 500 splits
        // 150 : 350 — the smaller centre's rounded share, and the larger
        // centre absorbing whatever rounding left over.
        $invoiceId = $this->seedInvoice($companyId, [
            ['ticket_id' => $ticketA, 'amount_paise' => 300000],
            ['ticket_id' => $ticketB, 'amount_paise' => 700000],
        ], totalPaise: 1000000);

        $this->settlement->recordInvoicePayment($invoiceId, 500000, 'REF2', null);

        $this->assertSame(150000, $this->savings->balance($centerA)->paise);
        $this->assertSame(350000, $this->savings->balance($centerB)->paise);
    }

    // -----------------------------------------------------------------
    // seeding
    // -----------------------------------------------------------------

    private function seedCenter(string $letter): int
    {
        $suffix = uniqid();
        $centers = $this->fetchTable('ServiceCenters');
        $center = $centers->newEntity([
            'code' => 'SIT' . $letter . $suffix,
            'name' => 'Savings Integration Centre ' . $letter,
            'is_active' => true,
        ], ['validate' => false]);
        $centers->saveOrFail($center);

        return (int)$center->id;
    }

    private function seedTechnician(int $centerId): int
    {
        $suffix = uniqid();
        $technicians = $this->fetchTable('Technicians');
        $technician = $technicians->newEntity([
            'service_center_id' => $centerId,
            'code' => 'SITT' . $suffix,
            'name' => 'Savings Integration Technician',
            'phone' => '9000000010',
            'is_active' => true,
        ], ['validate' => false]);
        $technicians->saveOrFail($technician, ['checkRules' => false]);

        return (int)$technician->id;
    }

    private function seedPayout(int $technicianId, int $netPaise, string $status): int
    {
        $today = DateTime::now();
        $payouts = $this->fetchTable('TechnicianPayouts');
        $payout = $payouts->newEntity([
            'technician_id' => $technicianId,
            'payout_no' => 'PAY-SIT-' . uniqid(),
            'period_start' => $today->format('Y-m-01'),
            'period_end' => $today->format('Y-m-d'),
            'status' => $status,
            'gross_paise' => $netPaise,
            'net_paise' => $netPaise,
        ], ['validate' => false]);
        $payouts->saveOrFail($payout, ['checkRules' => false]);

        return (int)$payout->id;
    }

    private function seedCompany(): int
    {
        $suffix = uniqid();
        $companies = $this->fetchTable('Companies');
        $company = $companies->newEntity([
            'code' => 'SITC' . $suffix,
            'name' => 'Savings Integration Company',
        ], ['validate' => false]);
        $companies->saveOrFail($company);

        return (int)$company->id;
    }

    private function seedTicket(int $companyId, int $centerId): int
    {
        $now = DateTime::now();

        $customers = $this->fetchTable('Customers');
        $customer = $customers->newEntity([
            'name' => 'Savings Integration Customer',
            'phone' => '9000000011',
        ], ['validate' => false]);
        $customers->saveOrFail($customer);

        $jobTypes = $this->fetchTable('JobTypes');
        $jobType = $jobTypes->newEntity([
            'code' => 'sit_call_' . uniqid(),
            'name' => 'Savings integration call',
        ], ['validate' => false]);
        $jobTypes->saveOrFail($jobType);

        $tickets = $this->fetchTable('Tickets');
        $ticket = $tickets->newEntity([
            'customer_id' => (int)$customer->id,
            'job_type_id' => (int)$jobType->id,
            'company_id' => $companyId,
            'service_center_id' => $centerId,
            'ticket_no' => 'SIT-' . uniqid(),
            'status' => 'closed',
            'warranty_scope' => 'in_warranty',
            'received_at' => $now,
            'closed_at' => $now,
            'created' => $now,
            'modified' => $now,
        ], ['validate' => false]);
        $tickets->saveOrFail($ticket, ['checkRules' => false]);

        return (int)$ticket->id;
    }

    /**
     * @param list<array{ticket_id: int, amount_paise: int}> $lines
     */
    private function seedInvoice(int $companyId, array $lines, ?int $totalPaise = null): int
    {
        $today = DateTime::now();
        $total = $totalPaise ?? array_sum(array_column($lines, 'amount_paise'));

        $invoices = $this->fetchTable('CompanyInvoices');
        $invoice = $invoices->newEntity([
            'company_id' => $companyId,
            'invoice_no' => 'INV-SIT-' . uniqid(),
            'period_start' => $today->format('Y-m-01'),
            'period_end' => $today->format('Y-m-d'),
            'status' => 'sent',
            'subtotal_paise' => $total,
            'total_paise' => $total,
        ], ['validate' => false]);
        $invoices->saveOrFail($invoice, ['checkRules' => false]);

        $invoiceLines = $this->fetchTable('CompanyInvoiceLines');
        foreach ($lines as $line) {
            $invoiceLine = $invoiceLines->newEntity([
                'company_invoice_id' => $invoice->id,
                'ticket_id' => $line['ticket_id'],
                'description' => 'Savings integration line',
                'amount_paise' => $line['amount_paise'],
            ], ['validate' => false]);
            $invoiceLines->saveOrFail($invoiceLine, ['checkRules' => false]);
        }

        return (int)$invoice->id;
    }
}
