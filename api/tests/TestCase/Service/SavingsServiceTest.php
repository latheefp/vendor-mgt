<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Domain\Money;
use App\Service\SavingsService;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * The append-only cash ledger's arithmetic.
 *
 * The property worth protecting is the one this whole design exists for:
 * `balance_after_paise` on the newest row is always the true balance, so
 * a reader never has to sum history to trust it. Every test here writes
 * its own centre and rolls it back, so it runs against a database with
 * or without seeds in it.
 */
class SavingsServiceTest extends TestCase
{
    use LocatorAwareTrait;

    private SavingsService $savings;
    private int $centerId;
    private int $otherCenterId;

    public function setUp(): void
    {
        parent::setUp();

        ConnectionManager::get('test')->begin();

        $this->savings = new SavingsService();
        $this->seedCenters();
    }

    public function tearDown(): void
    {
        ConnectionManager::get('test')->rollback();

        parent::tearDown();
    }

    public function testBalanceStartsAtZero(): void
    {
        $this->assertTrue($this->savings->balance($this->centerId)->isZero());
    }

    public function testCreditRaisesTheBalance(): void
    {
        $entry = $this->savings->credit(
            $this->centerId,
            Money::fromRupees(500),
            'invoice_payment',
            null,
            'Test credit',
        );

        $this->assertSame(50000, $entry['balance_after']['paise']);
        $this->assertSame(50000, $this->savings->balance($this->centerId)->paise);
    }

    public function testDebitLowersTheBalance(): void
    {
        $this->savings->credit($this->centerId, Money::fromRupees(500), 'invoice_payment', null, 'Funding');

        $entry = $this->savings->debit(
            $this->centerId,
            Money::fromRupees(200),
            'technician_payout',
            null,
            'Test debit',
        );

        $this->assertSame(30000, $entry['balance_after']['paise']);
        $this->assertSame(30000, $this->savings->balance($this->centerId)->paise);
    }

    public function testDebitCanTakeTheBalanceNegative(): void
    {
        // Nothing here forbids paying a technician before the invoice that
        // funds it has been collected — the ledger should say so plainly
        // rather than silently refuse to record what actually happened.
        $entry = $this->savings->debit($this->centerId, Money::fromRupees(100), 'technician_payout', null, 'Early payout');

        $this->assertSame(-10000, $entry['balance_after']['paise']);
    }

    public function testBalancesDoNotLeakBetweenCenters(): void
    {
        $this->savings->credit($this->centerId, Money::fromRupees(500), 'invoice_payment', null, 'Funding A');

        $this->assertTrue($this->savings->balance($this->otherCenterId)->isZero());
    }

    public function testAdjustPositiveCredits(): void
    {
        $entry = $this->savings->adjust($this->centerId, Money::fromRupees(150), 'Found cash in the drawer');

        $this->assertSame('credit', $entry['entry_type']);
        $this->assertSame(15000, $this->savings->balance($this->centerId)->paise);
    }

    public function testAdjustNegativeDebits(): void
    {
        $this->savings->credit($this->centerId, Money::fromRupees(500), 'invoice_payment', null, 'Funding');

        $entry = $this->savings->adjust($this->centerId, Money::fromRupees(-100), 'Bank fee');

        $this->assertSame('debit', $entry['entry_type']);
        $this->assertSame(40000, $this->savings->balance($this->centerId)->paise);
    }

    public function testLedgerReturnsNewestFirst(): void
    {
        $this->savings->credit($this->centerId, Money::fromRupees(100), 'invoice_payment', null, 'First');
        $this->savings->credit($this->centerId, Money::fromRupees(50), 'invoice_payment', null, 'Second');

        $ledger = $this->savings->ledger($this->centerId);

        $this->assertCount(2, $ledger);
        $this->assertSame('Second', $ledger[0]['description']);
        $this->assertSame('First', $ledger[1]['description']);
    }

    public function testBalancesOverviewIncludesEveryCenter(): void
    {
        $this->savings->credit($this->centerId, Money::fromRupees(300), 'invoice_payment', null, 'Funding A');

        $byId = [];
        foreach ($this->savings->balances() as $row) {
            $byId[$row['service_center']['id']] = $row['balance']['paise'];
        }

        $this->assertSame(30000, $byId[$this->centerId]);
        $this->assertSame(0, $byId[$this->otherCenterId]);
    }

    private function seedCenters(): void
    {
        $suffix = uniqid();
        $centers = $this->fetchTable('ServiceCenters');

        foreach (['centerId' => 'A', 'otherCenterId' => 'B'] as $property => $letter) {
            $center = $centers->newEntity([
                'code' => 'SAVT' . $letter . $suffix,
                'name' => 'Savings Test Centre ' . $letter,
                'is_active' => true,
            ], ['validate' => false]);
            $centers->saveOrFail($center);
            $this->{$property} = (int)$center->id;
        }
    }
}
