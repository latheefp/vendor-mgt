<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\SpareService;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * The stock ledger's arithmetic.
 *
 * These are here because the balance was wrong in a way that nothing
 * would have reported: issuing a part to a technician and then fitting it
 * took two off the centre for one physical part, and the technician's own
 * holding could not be derived at all. Both are silent — the number simply
 * drifts, and by the time anyone counts a shelf there is no way back to
 * which movement was wrong.
 *
 * Every test writes its own data and rolls it back, so the suite can run
 * against a database with or without seeds in it.
 */
class SpareStockTest extends TestCase
{
    use LocatorAwareTrait;

    private SpareService $spares;
    private int $companyId;
    private int $centreId;
    private int $otherCentreId;
    private int $technicianId;
    private int $partId;

    public function setUp(): void
    {
        parent::setUp();

        ConnectionManager::get('test')->begin();

        $this->spares = new SpareService();
        $this->seedCatalogue();
    }

    public function tearDown(): void
    {
        ConnectionManager::get('test')->rollback();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // locations
    // -----------------------------------------------------------------

    public function testReceiptLandsOnTheShelf(): void
    {
        $this->receive(10);

        $this->assertSame(10, $this->spares->balanceAtCentre($this->partId, $this->centreId));
        $this->assertSame(10, $this->spares->balanceAtCentreTotal($this->partId, $this->centreId));
        $this->assertSame(0, $this->spares->balanceWithTechnician($this->partId, $this->technicianId));
    }

    /**
     * The one that was wrong: a part in a bag has left the shelf but not
     * the centre.
     */
    public function testIssuingMovesStockWithoutLosingIt(): void
    {
        $this->receive(10);

        $result = $this->spares->issueToTechnician($this->partId, $this->centreId, $this->technicianId, 3);

        $this->assertTrue($result['ok']);
        $this->assertCount(2, $result['movement_ids'], 'A transfer is two rows, one per location.');

        $this->assertSame(7, $this->spares->balanceAtCentre($this->partId, $this->centreId));
        $this->assertSame(3, $this->spares->balanceWithTechnician($this->partId, $this->technicianId));
        $this->assertSame(
            10,
            $this->spares->balanceAtCentreTotal($this->partId, $this->centreId),
            'The centre still owns all ten; three of them are in a bag.',
        );
    }

    public function testIssuingRefusesMoreThanTheShelfHolds(): void
    {
        $this->receive(2);

        $result = $this->spares->issueToTechnician($this->partId, $this->centreId, $this->technicianId, 3);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('quantity', $result['errors']);
        $this->assertSame(2, $this->spares->balanceAtCentre($this->partId, $this->centreId));
    }

    /**
     * The bug this whole model exists to prevent. One panel is issued and
     * then fitted; the centre must be one down, not two.
     */
    public function testIssueThenConsumeRemovesOnePart(): void
    {
        $this->receive(5);
        $this->spares->issueToTechnician($this->partId, $this->centreId, $this->technicianId, 1);

        $ticketId = $this->seedTicket();
        $result = $this->spares->consumeOnTicket($ticketId, ['spare_part_id' => $this->partId, 'quantity' => 1]);

        $this->assertTrue($result['ok']);
        $this->assertSame(
            4,
            $this->spares->balanceAtCentreTotal($this->partId, $this->centreId),
            'One physical part left the building, so the centre falls by one.',
        );
        $this->assertSame(
            0,
            $this->spares->balanceWithTechnician($this->partId, $this->technicianId),
            'It came out of the bag it was issued into.',
        );
        $this->assertSame(4, $this->spares->balanceAtCentre($this->partId, $this->centreId));
    }

    /**
     * Nothing was issued first, so the part came straight off the shelf
     * and the shelf has to say so.
     */
    public function testConsumingWithoutAnIssueComesOffTheShelf(): void
    {
        $this->receive(5);

        $ticketId = $this->seedTicket();
        $this->spares->consumeOnTicket($ticketId, ['spare_part_id' => $this->partId, 'quantity' => 2]);

        $this->assertSame(3, $this->spares->balanceAtCentre($this->partId, $this->centreId));
        $this->assertSame(0, $this->spares->balanceWithTechnician($this->partId, $this->technicianId));
    }

    /**
     * Recording work that physically happened is never blocked on the back
     * office having booked the challan. The resulting negative is handed
     * back to be shown rather than swallowed.
     */
    public function testConsumingWithNoStockIsRecordedAndFlagged(): void
    {
        $ticketId = $this->seedTicket();

        $result = $this->spares->consumeOnTicket($ticketId, ['spare_part_id' => $this->partId, 'quantity' => 1]);

        $this->assertTrue($result['ok']);
        $this->assertSame(-1, $result['stock_remaining']);
        $this->assertNotNull($result['stock_warning']);
    }

    public function testUnusedPartComesBackToTheShelf(): void
    {
        $this->receive(4);
        $this->spares->issueToTechnician($this->partId, $this->centreId, $this->technicianId, 2);

        $result = $this->spares->returnGoodFromTechnician($this->partId, $this->centreId, $this->technicianId, 2);

        $this->assertTrue($result['ok']);
        $this->assertSame(4, $this->spares->balanceAtCentre($this->partId, $this->centreId));
        $this->assertSame(0, $this->spares->balanceWithTechnician($this->partId, $this->technicianId));
    }

    public function testReturnRefusesMoreThanTheTechnicianHolds(): void
    {
        $this->receive(4);
        $this->spares->issueToTechnician($this->partId, $this->centreId, $this->technicianId, 1);

        $result = $this->spares->returnGoodFromTechnician($this->partId, $this->centreId, $this->technicianId, 2);

        $this->assertFalse($result['ok']);
        $this->assertSame(3, $this->spares->balanceAtCentre($this->partId, $this->centreId));
    }

    /**
     * Balances are per centre. Stock at one branch is not available to
     * another, however much of it there is.
     */
    public function testBalancesDoNotLeakBetweenCentres(): void
    {
        $this->receive(6);

        $this->assertSame(0, $this->spares->balanceAtCentre($this->partId, $this->otherCentreId));

        $result = $this->spares->issueToTechnician($this->partId, $this->otherCentreId, $this->technicianId, 1);

        $this->assertFalse($result['ok']);
    }

    // -----------------------------------------------------------------
    // corrections
    // -----------------------------------------------------------------

    public function testCountRecordsTheDifferenceRatherThanTheTotal(): void
    {
        $this->receive(10);

        $result = $this->spares->adjustToCount($this->partId, $this->centreId, 8, 'Two missing at the quarterly count.');

        $this->assertTrue($result['ok']);
        $this->assertSame(-2, $result['delta']);
        $this->assertSame(8, $this->spares->balanceAtCentre($this->partId, $this->centreId));

        // The receipt is still there to be read. A count that overwrote the
        // balance would have erased it.
        $movements = $this->spares->movements($this->partId, $this->centreId);
        $types = array_map(fn($m): string => $m->movement_type, $movements);
        $this->assertContains('received', $types);
        $this->assertContains('adjustment', $types);
    }

    public function testCountThatAgreesIsStillRecorded(): void
    {
        $this->receive(10);

        $result = $this->spares->adjustToCount($this->partId, $this->centreId, 10, 'Quarterly count.');

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['delta']);
        $this->assertSame(10, $this->spares->balanceAtCentre($this->partId, $this->centreId));
    }

    public function testWriteOffNeedsAReason(): void
    {
        $this->receive(3);

        $result = $this->spares->writeOff($this->partId, $this->centreId, 1, '   ');

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('reason', $result['errors']);
        $this->assertSame(3, $this->spares->balanceAtCentre($this->partId, $this->centreId));
    }

    public function testWriteOffRemovesStock(): void
    {
        $this->receive(3);

        $result = $this->spares->writeOff($this->partId, $this->centreId, 1, 'Dropped on the bench.');

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $this->spares->balanceAtCentre($this->partId, $this->centreId));
    }

    /**
     * A part recorded on the wrong job goes back to the bag it came out
     * of, not to the shelf. Crediting the shelf balances the centre and
     * leaves the technician holding stock they are not carrying — the same
     * silent drift the two-row model exists to prevent.
     */
    public function testRemovingAFittedPartCreditsTheLocationItLeft(): void
    {
        $this->receive(5);
        $this->spares->issueToTechnician($this->partId, $this->centreId, $this->technicianId, 1);

        $ticketId = $this->seedTicket();
        $fitted = $this->spares->consumeOnTicket($ticketId, ['spare_part_id' => $this->partId, 'quantity' => 1]);

        $result = $this->spares->removeFromTicket($fitted['ticket_spare_id']);

        $this->assertTrue($result['ok']);
        $this->assertSame(5, $this->spares->balanceAtCentreTotal($this->partId, $this->centreId));
        $this->assertSame(
            1,
            $this->spares->balanceWithTechnician($this->partId, $this->technicianId),
            'It went back into the bag it was issued into.',
        );
        $this->assertSame(4, $this->spares->balanceAtCentre($this->partId, $this->centreId));
    }

    public function testRemovingAPartTakenOffTheShelfCreditsTheShelf(): void
    {
        $this->receive(5);

        $ticketId = $this->seedTicket();
        $fitted = $this->spares->consumeOnTicket($ticketId, ['spare_part_id' => $this->partId, 'quantity' => 2]);

        $this->spares->removeFromTicket($fitted['ticket_spare_id']);

        $this->assertSame(5, $this->spares->balanceAtCentre($this->partId, $this->centreId));
        $this->assertSame(0, $this->spares->balanceWithTechnician($this->partId, $this->technicianId));
    }

    /**
     * The line goes, so a closure gate or an invoice reading the ticket
     * sees the part as never fitted. The movements stay: what the shelf
     * did is history, and history is not edited.
     */
    public function testRemovingAPartDropsTheLineButKeepsTheLedger(): void
    {
        $this->receive(2);

        $ticketId = $this->seedTicket();
        $fitted = $this->spares->consumeOnTicket($ticketId, ['spare_part_id' => $this->partId, 'quantity' => 1]);

        $this->spares->removeFromTicket($fitted['ticket_spare_id']);

        $this->assertSame(
            0,
            $this->fetchTable('TicketSpares')->find()->where(['ticket_id' => $ticketId])->count(),
        );

        $types = array_map(
            fn ($m): string => $m->movement_type,
            $this->spares->movements($this->partId, $this->centreId),
        );
        $this->assertContains('consumed', $types);
        $this->assertContains('adjustment', $types);
    }

    /**
     * Once the charges are frozen the line has been billed to somebody,
     * and `ticket_charges.ticket_spare_id` cascades on delete — removing
     * it here would take an invoiced charge with it.
     */
    public function testRemovingIsRefusedOnceChargesAreFrozen(): void
    {
        $this->receive(2);

        $ticketId = $this->seedTicket();
        $fitted = $this->spares->consumeOnTicket($ticketId, ['spare_part_id' => $this->partId, 'quantity' => 1]);

        $tickets = $this->fetchTable('Tickets');
        $ticket = $tickets->get($ticketId);
        $ticket->set('charges_frozen_at', DateTime::now());
        $tickets->saveOrFail($ticket, ['checkRules' => false]);

        $result = $this->spares->removeFromTicket($fitted['ticket_spare_id']);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('ticket', $result['errors']);
        $this->assertSame(
            1,
            $this->fetchTable('TicketSpares')->find()->where(['ticket_id' => $ticketId])->count(),
        );
        $this->assertSame(1, $this->spares->balanceAtCentre($this->partId, $this->centreId));
    }

    /**
     * The defective is already in the company's hands under a docket they
     * will credit against. Withdrawing the line it belongs to would leave
     * that consignment referring to nothing.
     */
    public function testRemovingIsRefusedAfterTheDefectiveWentBack(): void
    {
        $this->receive(2);

        $ticketId = $this->seedTicket();
        $fitted = $this->spares->consumeOnTicket($ticketId, [
            'spare_part_id' => $this->partId,
            'quantity' => 1,
            'is_defective_return' => true,
        ]);

        $this->spares->returnDefective($fitted['ticket_spare_id'], 'DKT-001');

        $result = $this->spares->removeFromTicket($fitted['ticket_spare_id']);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('ticket_spare_id', $result['errors']);
    }

    /**
     * Replacing a part is a removal followed by a fitting, and the stock
     * has to read as one part gone rather than two.
     */
    public function testReplacingAPartLeavesOneConsumption(): void
    {
        $this->receive(5);

        $ticketId = $this->seedTicket();
        $wrong = $this->spares->consumeOnTicket($ticketId, ['spare_part_id' => $this->partId, 'quantity' => 1]);
        $this->spares->removeFromTicket($wrong['ticket_spare_id']);
        $this->spares->consumeOnTicket($ticketId, ['spare_part_id' => $this->partId, 'quantity' => 1]);

        $this->assertSame(4, $this->spares->balanceAtCentre($this->partId, $this->centreId));
        $this->assertSame(
            1,
            $this->fetchTable('TicketSpares')->find()->where(['ticket_id' => $ticketId])->count(),
        );
    }

    /**
     * The part came in on a challan three weeks ago and the fitting was a
     * mis-entry. Putting it back must not hand it a fresh clause 10 window
     * — a part that has sat here for 45 days is 45 days old whatever the
     * paperwork did in between.
     */
    public function testRemovingDoesNotResetTheClause10Clock(): void
    {
        $arrived = DateTime::now()->subDays(45);
        $this->receive(1, $arrived);

        $ticketId = $this->seedTicket();
        $fitted = $this->spares->consumeOnTicket($ticketId, [
            'spare_part_id' => $this->partId,
            'quantity' => 1,
            'received_at' => $arrived->format('Y-m-d'),
        ]);

        $this->spares->removeFromTicket($fitted['ticket_spare_id']);

        $result = $this->spares->stockAgeing($this->companyId, $this->centreId);

        $this->assertCount(1, $result['lots']);
        $this->assertSame(1, $result['lots'][0]['quantity']);
        $this->assertTrue($result['lots'][0]['is_overdue'], 'The unit kept the age it arrived with.');
    }

    // -----------------------------------------------------------------
    // clause 10 ageing
    // -----------------------------------------------------------------

    /**
     * "Spare parts kept above 30 days will be considered as billed." Kept,
     * not fitted — a part that arrived and never moved is the case the
     * clause is about, and it appears on no ticket at all.
     */
    public function testStockPastTheWindowIsReportedOverdue(): void
    {
        $this->receive(2, DateTime::now()->subDays(45));

        $result = $this->spares->stockAgeing($this->companyId, $this->centreId);

        $this->assertCount(1, $result['lots']);
        $this->assertTrue($result['lots'][0]['is_overdue']);
        $this->assertSame(2, $result['totals']['overdue_units']);
        $this->assertSame(2 * 650000, $result['totals']['overdue_value_paise']);
    }

    public function testStockInsideTheWindowIsNotOverdue(): void
    {
        $this->receive(2, DateTime::now()->subDays(3));

        $result = $this->spares->stockAgeing($this->companyId, $this->centreId);

        $this->assertFalse($result['lots'][0]['is_overdue']);
        $this->assertSame(0, $result['totals']['overdue_units']);
        $this->assertSame(2, $result['totals']['units']);
    }

    /**
     * Receipts are consumed oldest-first, which is how a centre rotates
     * stock. What is left ages from the challan that is still open.
     */
    public function testConsumptionClearsTheOldestReceiptFirst(): void
    {
        $this->receive(2, DateTime::now()->subDays(50));
        $this->receive(2, DateTime::now()->subDays(2));

        $ticketId = $this->seedTicket();
        $this->spares->consumeOnTicket($ticketId, ['spare_part_id' => $this->partId, 'quantity' => 2]);

        $result = $this->spares->stockAgeing($this->companyId, $this->centreId);

        $this->assertCount(1, $result['lots'], 'The old challan is fully used up.');
        $this->assertSame(2, $result['lots'][0]['quantity']);
        $this->assertFalse($result['lots'][0]['is_overdue']);
    }

    /**
     * Carrying a part out and bringing it back does not make it newer.
     * Transfers were the reason the age reset on its own.
     */
    public function testTransfersDoNotResetTheClock(): void
    {
        $this->receive(2, DateTime::now()->subDays(40));

        $this->spares->issueToTechnician($this->partId, $this->centreId, $this->technicianId, 1);
        $this->spares->returnGoodFromTechnician($this->partId, $this->centreId, $this->technicianId, 1);

        $result = $this->spares->stockAgeing($this->companyId, $this->centreId);

        $this->assertCount(1, $result['lots']);
        $this->assertSame(2, $result['lots'][0]['quantity']);
        $this->assertTrue($result['lots'][0]['is_overdue']);
        $this->assertGreaterThanOrEqual(40, $result['lots'][0]['age_days']);
    }

    /**
     * A part in a technician's bag is still ours and still ageing. This is
     * the case the clause is most expensive on and the easiest to lose.
     */
    public function testStockInABagStillAges(): void
    {
        $this->receive(1, DateTime::now()->subDays(40));
        $this->spares->issueToTechnician($this->partId, $this->centreId, $this->technicianId, 1);

        $result = $this->spares->stockAgeing($this->companyId, $this->centreId);

        $this->assertCount(1, $result['lots']);
        $this->assertTrue($result['lots'][0]['is_overdue']);
    }

    public function testAgeingValuesLotsAtTheCostTheyArrivedAt(): void
    {
        $this->receive(2, DateTime::now()->subDays(40), unitCostPaise: 500000);

        $result = $this->spares->stockAgeing($this->companyId, $this->centreId);

        $this->assertSame(500000, $result['lots'][0]['unit_cost_paise']);
        $this->assertSame(1000000, $result['lots'][0]['value_paise']);
    }

    // -----------------------------------------------------------------
    // reporting
    // -----------------------------------------------------------------

    public function testStockListSplitsShelfFromBagsAndFlagsReorder(): void
    {
        $this->receive(6);
        $this->spares->issueToTechnician($this->partId, $this->centreId, $this->technicianId, 4);

        $rows = $this->spares->stockOnHand($this->companyId, $this->centreId);
        $row = $this->rowForPart($rows);

        $this->assertSame(6, $row['on_hand']);
        $this->assertSame(2, $row['on_shelf']);
        $this->assertSame(4, $row['with_technicians']);
        // Reorder is judged on the shelf: four of these are spoken for and
        // cannot be handed to the next job.
        $this->assertTrue($row['below_reorder']);
    }

    public function testTechnicianHoldingsNameThePersonAndTheValue(): void
    {
        $this->receive(5);
        $this->spares->issueToTechnician($this->partId, $this->centreId, $this->technicianId, 2);

        $holdings = $this->spares->technicianHoldings($this->centreId);

        $this->assertCount(1, $holdings);
        $this->assertSame($this->technicianId, (int)$holdings[0]['technician_id']);
        $this->assertSame(2, (int)$holdings[0]['quantity']);
        $this->assertSame(2 * 650000, (int)$holdings[0]['value_paise']);
    }

    public function testTechnicianHoldingNothingIsNotListed(): void
    {
        $this->receive(5);
        $this->spares->issueToTechnician($this->partId, $this->centreId, $this->technicianId, 2);
        $this->spares->returnGoodFromTechnician($this->partId, $this->centreId, $this->technicianId, 2);

        $this->assertSame([], $this->spares->technicianHoldings($this->centreId));
    }

    // -----------------------------------------------------------------
    // scaffolding
    // -----------------------------------------------------------------

    // -----------------------------------------------------------------
    // a part belongs to one company
    // -----------------------------------------------------------------

    /**
     * A part may only be worked against its own company's jobs.
     *
     * `consumeOnTicket()` has always refused the crossover, but the bag
     * movement runs first and used to be written whatever ticket it was
     * handed. That left a technician holding company A's panel against
     * company B's job — a state the fitting could never resolve and the
     * holdings report read straight through.
     */
    public function testIssuingAnotherCompanysPartAgainstATicketIsRefused(): void
    {
        $this->receive(5);

        $foreignPartId = $this->seedForeignPart();
        $ticketId = $this->seedTicket();

        $result = $this->spares->issueToTechnician(
            $foreignPartId,
            $this->centreId,
            $this->technicianId,
            1,
            $ticketId,
        );

        $this->assertFalse($result['ok'], 'A part from another company should not go out on this ticket.');
        $this->assertArrayHasKey('spare_part_id', $result['errors']);
    }

    /**
     * Issuing without a ticket names no company, so there is nothing to
     * contradict and the guard must stay out of the way.
     */
    public function testIssuingWithoutATicketIsUnaffected(): void
    {
        $this->receive(5);

        $result = $this->spares->issueToTechnician($this->partId, $this->centreId, $this->technicianId, 1);

        $this->assertTrue($result['ok']);
    }

    /**
     * Clause 9 settles a consignment, and the credit note comes back
     * against the docket. A box holding two companies' parts is one
     * neither of them can credit in full.
     */
    public function testADefectiveBatchSpanningTwoCompaniesIsRefused(): void
    {
        $this->receive(2);

        $ticketId = $this->seedTicket();
        $ours = $this->spares->consumeOnTicket($ticketId, [
            'spare_part_id' => $this->partId,
            'quantity' => 1,
            'is_defective_return' => true,
        ]);

        $theirs = $this->seedForeignDefective();

        $result = $this->spares->returnDefectiveBatch(
            [$ours['ticket_spare_id'], $theirs],
            'DKT-MIXED',
        );

        $this->assertFalse($result['ok'], 'One docket cannot cover two companies.');
        $this->assertArrayHasKey('ticket_spare_ids', $result['errors']);
    }

    /**
     * The same batch, all from one company, still goes.
     */
    public function testADefectiveBatchWithinOneCompanyStillGoes(): void
    {
        $this->receive(2);

        $ticketId = $this->seedTicket();
        $fitted = $this->spares->consumeOnTicket($ticketId, [
            'spare_part_id' => $this->partId,
            'quantity' => 1,
            'is_defective_return' => true,
        ]);

        $result = $this->spares->returnDefectiveBatch([$fitted['ticket_spare_id']], 'DKT-CLEAN');

        $this->assertTrue($result['ok']);
        $this->assertSame([$fitted['ticket_spare_id']], $result['returned']);
    }

    private function receive(int $quantity, ?DateTime $at = null, ?int $unitCostPaise = null): void
    {
        $result = $this->spares->receive(
            $this->partId,
            $this->centreId,
            $quantity,
            'CHL-' . $quantity . '-' . ($at?->format('Ymd') ?? 'now'),
            $at,
            null,
            $unitCostPaise,
        );

        $this->assertTrue($result['ok'], 'The receipt should have been accepted.');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function rowForPart(array $rows): array
    {
        foreach ($rows as $row) {
            if ($row['spare_part_id'] === $this->partId) {
                return $row;
            }
        }

        $this->fail('The part under test was missing from the stock list.');
    }

    private function seedCatalogue(): void
    {
        $now = DateTime::now();
        $suffix = uniqid();

        $companies = $this->fetchTable('Companies');
        $company = $companies->newEntity([
            'code' => 'TSTV' . $suffix,
            'name' => 'Stock Test Company',
        ], ['validate' => false]);
        $companies->saveOrFail($company);
        $this->companyId = (int)$company->id;

        $centres = $this->fetchTable('ServiceCenters');
        foreach (['centreId' => 'A', 'otherCentreId' => 'B'] as $property => $letter) {
            $centre = $centres->newEntity([
                'code' => 'TSTC' . $letter . $suffix,
                'name' => 'Stock Test Centre ' . $letter,
                'is_active' => true,
            ], ['validate' => false]);
            $centres->saveOrFail($centre);
            $this->{$property} = (int)$centre->id;
        }

        $technicians = $this->fetchTable('Technicians');
        $technician = $technicians->newEntity([
            'service_center_id' => $this->centreId,
            'code' => 'TSTT' . $suffix,
            'name' => 'Stock Test Technician',
            'phone' => '9000000000',
            'is_active' => true,
        ], ['validate' => false]);
        $technicians->saveOrFail($technician);
        $this->technicianId = (int)$technician->id;

        $parts = $this->fetchTable('SpareParts');
        $part = $parts->newEntity([
            'company_id' => $this->companyId,
            'part_no' => 'TST-PANEL-' . $suffix,
            'name' => 'Test panel',
            'cost_paise' => 650000,
            'is_serialized' => false,
            'reorder_level' => 3,
            'is_active' => true,
            'created' => $now,
            'modified' => $now,
        ], ['validate' => false]);
        $parts->saveOrFail($part);
        $this->partId = (int)$part->id;
    }

    /**
     * A second company with a part of its own, for the crossover tests.
     *
     * @return int The foreign part's id.
     */
    private function seedForeignPart(): int
    {
        $now = DateTime::now();
        $suffix = uniqid();

        $companies = $this->fetchTable('Companies');
        $company = $companies->newEntity([
            'code' => 'OTHV' . $suffix,
            'name' => 'Other Test Company',
        ], ['validate' => false]);
        $companies->saveOrFail($company);

        $parts = $this->fetchTable('SpareParts');
        $part = $parts->newEntity([
            'company_id' => (int)$company->id,
            'part_no' => 'OTH-PANEL-' . $suffix,
            'name' => 'Other company panel',
            'cost_paise' => 650000,
            'is_serialized' => false,
            'reorder_level' => 3,
            'is_active' => true,
            'created' => $now,
            'modified' => $now,
        ], ['validate' => false]);
        $parts->saveOrFail($part);

        return (int)$part->id;
    }

    /**
     * A defective awaiting return that belongs to a different company, so
     * a batch can be built that spans two of them.
     *
     * Written straight to the table rather than through `consumeOnTicket()`
     * because the point of the fixture is the crossover the service layer
     * exists to prevent.
     *
     * @return int The ticket_spares id.
     */
    private function seedForeignDefective(): int
    {
        $now = DateTime::now();
        $foreignPartId = $this->seedForeignPart();

        $companyId = (int)$this->fetchTable('SpareParts')
            ->get($foreignPartId)
            ->company_id;

        $customers = $this->fetchTable('Customers');
        $customer = $customers->newEntity([
            'name' => 'Other Test Customer',
            'phone' => '9000000002',
        ], ['validate' => false]);
        $customers->saveOrFail($customer);

        $jobTypes = $this->fetchTable('JobTypes');
        $jobType = $jobTypes->newEntity([
            'code' => 'oth_call_' . uniqid(),
            'name' => 'Other stock test call',
        ], ['validate' => false]);
        $jobTypes->saveOrFail($jobType);

        $tickets = $this->fetchTable('Tickets');
        $ticket = $tickets->newEntity([
            'customer_id' => (int)$customer->id,
            'job_type_id' => (int)$jobType->id,
            'company_id' => $companyId,
            'service_center_id' => $this->centreId,
            'assigned_technician_id' => $this->technicianId,
            'ticket_no' => 'OTH-' . uniqid(),
            'status' => 'in_progress',
            'warranty_scope' => 'in_warranty',
            'received_at' => $now,
            'created' => $now,
            'modified' => $now,
        ], ['validate' => false]);
        $tickets->saveOrFail($ticket, ['checkRules' => false]);

        $ticketSpares = $this->fetchTable('TicketSpares');
        $spare = $ticketSpares->newEntity([
            'ticket_id' => (int)$ticket->id,
            'spare_part_id' => $foreignPartId,
            'quantity' => 1,
            'charged_to' => 'company',
            'unit_cost_paise' => 650000,
            'is_defective_return' => true,
            'issued_from_center_id' => $this->centreId,
            'created' => $now,
            'modified' => $now,
        ], ['validate' => false]);
        $ticketSpares->saveOrFail($spare);

        return (int)$spare->id;
    }

    /**
     * A ticket to hang a consumption on. In-warranty on purpose: it keeps
     * the pricing path out of these tests, which are about where the part
     * went and not what it cost the customer.
     */
    private function seedTicket(): int
    {
        $now = DateTime::now();

        $customers = $this->fetchTable('Customers');
        $customer = $customers->newEntity([
            'name' => 'Stock Test Customer',
            'phone' => '9000000001',
        ], ['validate' => false]);
        $customers->saveOrFail($customer);

        $jobTypes = $this->fetchTable('JobTypes');
        $jobType = $jobTypes->newEntity([
            'code' => 'tst_call_' . uniqid(),
            'name' => 'Stock test call',
        ], ['validate' => false]);
        $jobTypes->saveOrFail($jobType);

        $tickets = $this->fetchTable('Tickets');

        $ticket = $tickets->newEntity([
            'customer_id' => (int)$customer->id,
            'job_type_id' => (int)$jobType->id,
            'company_id' => $this->companyId,
            'service_center_id' => $this->centreId,
            'assigned_technician_id' => $this->technicianId,
            'ticket_no' => 'TST-' . uniqid(),
            'status' => 'in_progress',
            'warranty_scope' => 'in_warranty',
            'received_at' => $now,
            'created' => $now,
            'modified' => $now,
        ], ['validate' => false]);

        $tickets->saveOrFail($ticket, ['checkRules' => false]);

        return (int)$ticket->id;
    }
}
