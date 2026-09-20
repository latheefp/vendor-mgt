<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Domain\Enum\ChargeLineType;
use App\Service\TicketAdjustmentService;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * Which lines may be added to a ticket, and when.
 *
 * These exist because the distinction was lost once already. Adding a BOQ
 * service line to an open job and correcting a bill already sent were
 * routed through one endpoint, and making the first work was done by
 * freezing the ticket — which left an `in_progress` job that refused
 * parts and refused closure, with nothing in the UI saying why.
 *
 * The rule these lock down: `charges_frozen_at` is the boundary. Before
 * it, extra work is a BOQ line and adding one must not freeze anything.
 * After it, the only sanctioned change is an adjustment.
 *
 * Every test writes its own data and rolls it back, so the suite can run
 * against a database with or without seeds in it.
 */
class TicketChargeLineTest extends TestCase
{
    use LocatorAwareTrait;

    private TicketAdjustmentService $charges;
    private int $companyId;
    private int $ticketId;
    private int $jobTypeId;
    private int $rateCardItemId;
    private int $zeroRateCardItemId;
    private int $otherCompanyRateCardItemId;

    public function setUp(): void
    {
        parent::setUp();

        ConnectionManager::get('test')->begin();

        $this->charges = new TicketAdjustmentService();
        $this->ticketId = $this->seedTicket();
        $this->seedRateCardItems();
    }

    public function tearDown(): void
    {
        ConnectionManager::get('test')->rollback();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // BOQ lines on an open ticket
    // -----------------------------------------------------------------

    public function testServiceLineIsAcceptedOnAnOpenTicket(): void
    {
        $result = $this->addServiceLine($this->rateCardItemId);

        $this->assertTrue($result['ok']);

        $line = $this->fetchTable('TicketCharges')->get($result['charge_id']);
        $this->assertSame(ChargeLineType::Boq->value, $line->line_type);
        $this->assertSame(120000, (int)$line->amount_paise);
        $this->assertSame($this->rateCardItemId, (int)$line->rate_card_item_id);
    }

    /**
     * The regression itself. Recording agreed work must not lock the
     * ledger — a frozen `in_progress` ticket cannot take a part and
     * cannot be closed, so it is stuck with no route out from the UI.
     */
    public function testAddingAServiceLineDoesNotFreezeTheTicket(): void
    {
        $this->addServiceLine($this->rateCardItemId);

        $ticket = $this->fetchTable('Tickets')->get($this->ticketId);

        $this->assertNull($ticket->charges_frozen_at);
        $this->assertSame('in_progress', $ticket->status);
    }

    public function testServiceLineIsRefusedOnceChargesAreFrozen(): void
    {
        $this->freezeCharges();

        $result = $this->addServiceLine($this->rateCardItemId);

        $this->assertFalse($result['ok']);
        $this->assertSame('frozen', $result['code']);
        $this->assertSame(
            0,
            $this->fetchTable('TicketCharges')->find()
                ->where(['ticket_id' => $this->ticketId])
                ->count(),
        );
    }

    public function testServiceLineNeedsARateCardItem(): void
    {
        $result = $this->addServiceLine(null);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('rate_card_item_id', $result['errors']);
    }

    /**
     * Rates come from the rate card only: an amount typed by the desk, or
     * an item belonging to a different company's card, is refused rather
     * than trusted.
     */
    public function testServiceLineRefusesAnItemNotOnThisCompanysActiveCard(): void
    {
        $result = $this->addServiceLine($this->otherCompanyRateCardItemId);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('rate_card_item_id', $result['errors']);
        $this->assertSame(
            0,
            $this->fetchTable('TicketCharges')->find()
                ->where(['ticket_id' => $this->ticketId])
                ->count(),
        );
    }

    public function testServiceLineRefusesAZeroPricedItem(): void
    {
        $result = $this->addServiceLine($this->zeroRateCardItemId);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('amount', $result['errors']);
    }

    // -----------------------------------------------------------------
    // adjustments after the freeze
    // -----------------------------------------------------------------

    /**
     * The other half of the boundary. An adjustment before the freeze was
     * silently freezing the ticket to make itself valid; it must refuse.
     */
    public function testAdjustmentIsRefusedBeforeTheFreeze(): void
    {
        $result = $this->charges->add($this->ticketId, [
            'ledger' => 'company_receivable',
            'amount' => '400',
            'reason' => 'Basic service charge',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('not_frozen', $result['code']);

        $ticket = $this->fetchTable('Tickets')->get($this->ticketId);
        $this->assertNull($ticket->charges_frozen_at);
    }

    public function testAdjustmentIsAcceptedAfterTheFreeze(): void
    {
        $this->freezeCharges();

        $result = $this->charges->add($this->ticketId, [
            'ledger' => 'company_receivable',
            'amount' => '-150',
            'reason' => 'Conceded dispute',
        ]);

        $this->assertTrue($result['ok']);

        $line = $this->fetchTable('TicketCharges')->get($result['charge_id']);
        $this->assertSame(ChargeLineType::Adjustment->value, $line->line_type);
        $this->assertSame(-15000, (int)$line->amount_paise);
    }

    // -----------------------------------------------------------------
    // why the ledger is locked
    // -----------------------------------------------------------------

    public function testLedgerReportsNoFreezeContextWhileOpen(): void
    {
        $this->assertNull($this->charges->ledger($this->ticketId)['freeze']);
    }

    /**
     * The gap that made the original bug a database query rather than a
     * glance at the screen.
     */
    public function testLedgerExplainsWhoFrozeTheChargesAndWhy(): void
    {
        $this->freezeCharges();
        $this->fetchTable('TicketEvents')->saveOrFail(
            $this->fetchTable('TicketEvents')->newEntity([
                'ticket_id' => $this->ticketId,
                'event_type' => 'charges_frozen',
                'description' => 'Charges frozen on closure — 3 line(s).',
                'occurred_at' => DateTime::now(),
                'created' => DateTime::now(),
            ], ['validate' => false]),
        );

        $freeze = $this->charges->ledger($this->ticketId)['freeze'];

        $this->assertNotNull($freeze);
        $this->assertSame('Charges frozen on closure — 3 line(s).', $freeze['reason']);
        $this->assertNotEmpty($freeze['frozen_at']);
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function addServiceLine(?int $rateCardItemId): array
    {
        return $this->charges->addServiceLine($this->ticketId, [
            'ledger' => 'company_receivable',
            'rate_card_item_id' => $rateCardItemId,
        ]);
    }

    private function freezeCharges(): void
    {
        $tickets = $this->fetchTable('Tickets');
        $ticket = $tickets->get($this->ticketId);
        $ticket->set('charges_frozen_at', DateTime::now());
        $tickets->saveOrFail($ticket, ['checkRules' => false]);
    }

    private function seedTicket(): int
    {
        $now = DateTime::now();
        $suffix = uniqid();

        $companies = $this->fetchTable('Companies');
        $company = $companies->newEntity([
            'code' => 'BOQ' . $suffix,
            'name' => 'BOQ Test Company',
        ], ['validate' => false]);
        $companies->saveOrFail($company);
        $this->companyId = (int)$company->id;

        $centres = $this->fetchTable('ServiceCenters');
        $centre = $centres->newEntity([
            'code' => 'BOQC' . $suffix,
            'name' => 'BOQ Test Centre',
            'is_active' => true,
        ], ['validate' => false]);
        $centres->saveOrFail($centre);

        $customers = $this->fetchTable('Customers');
        $customer = $customers->newEntity([
            'name' => 'BOQ Test Customer',
            'phone' => '9000000002',
        ], ['validate' => false]);
        $customers->saveOrFail($customer);

        $jobTypes = $this->fetchTable('JobTypes');
        $jobType = $jobTypes->newEntity([
            'code' => 'boq_call_' . $suffix,
            'name' => 'BOQ test call',
        ], ['validate' => false]);
        $jobTypes->saveOrFail($jobType);
        $this->jobTypeId = (int)$jobType->id;

        $tickets = $this->fetchTable('Tickets');
        $ticket = $tickets->newEntity([
            'customer_id' => (int)$customer->id,
            'job_type_id' => (int)$jobType->id,
            'company_id' => $this->companyId,
            'service_center_id' => (int)$centre->id,
            'ticket_no' => 'BOQ-' . $suffix,
            'status' => 'in_progress',
            'warranty_scope' => 'out_of_warranty',
            'received_at' => $now,
            'created' => $now,
            'modified' => $now,
        ], ['validate' => false]);
        $tickets->saveOrFail($ticket, ['checkRules' => false]);

        return (int)$ticket->id;
    }

    /**
     * An active rate card for the ticket's company (with a priced item and
     * a zero-priced one), plus an active card for a second, unrelated
     * company — so tests can prove an item is scoped to its own company.
     */
    private function seedRateCardItems(): void
    {
        $rateCards = $this->fetchTable('RateCards');
        $items = $this->fetchTable('RateCardItems');
        $suffix = uniqid();

        $card = $rateCards->newEntity([
            'company_id' => $this->companyId,
            'name' => 'BOQ Test Card',
            'version' => 1,
            'status' => 'active',
            'effective_from' => '2020-01-01',
        ], ['validate' => false]);
        $rateCards->saveOrFail($card, ['checkRules' => false]);

        $item = $items->newEntity([
            'rate_card_id' => (int)$card->id,
            'job_type_id' => $this->jobTypeId,
            'warranty_scope' => 'out_of_warranty',
            'amount_paise' => 120000,
            'payer' => 'customer',
            'label' => 'Gas refilling',
            'is_active' => true,
        ], ['validate' => false]);
        $items->saveOrFail($item, ['checkRules' => false]);
        $this->rateCardItemId = (int)$item->id;

        $zeroItem = $items->newEntity([
            'rate_card_id' => (int)$card->id,
            'job_type_id' => $this->jobTypeId,
            'warranty_scope' => 'out_of_warranty',
            'amount_paise' => 0,
            'payer' => 'customer',
            'label' => 'Free courtesy check',
            'is_active' => true,
        ], ['validate' => false]);
        $items->saveOrFail($zeroItem, ['checkRules' => false]);
        $this->zeroRateCardItemId = (int)$zeroItem->id;

        $companies = $this->fetchTable('Companies');
        $otherCompany = $companies->newEntity([
            'code' => 'BOQOTHER' . $suffix,
            'name' => 'BOQ Other Company',
        ], ['validate' => false]);
        $companies->saveOrFail($otherCompany);

        $otherCard = $rateCards->newEntity([
            'company_id' => (int)$otherCompany->id,
            'name' => 'BOQ Other Card',
            'version' => 1,
            'status' => 'active',
            'effective_from' => '2020-01-01',
        ], ['validate' => false]);
        $rateCards->saveOrFail($otherCard, ['checkRules' => false]);

        $otherItem = $items->newEntity([
            'rate_card_id' => (int)$otherCard->id,
            'job_type_id' => $this->jobTypeId,
            'warranty_scope' => 'out_of_warranty',
            'amount_paise' => 50000,
            'payer' => 'customer',
            'label' => 'Other company service',
            'is_active' => true,
        ], ['validate' => false]);
        $items->saveOrFail($otherItem, ['checkRules' => false]);
        $this->otherCompanyRateCardItemId = (int)$otherItem->id;
    }
}
