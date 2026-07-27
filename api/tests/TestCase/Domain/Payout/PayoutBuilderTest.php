<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Payout;

use App\Domain\Charge\ChargeBuilder;
use App\Domain\Charge\ChargeSet;
use App\Domain\Enum\ChargeLineType;
use App\Domain\Enum\Ledger;
use App\Domain\Enum\PayoutModel;
use App\Domain\Enum\WarrantyScope;
use App\Domain\Money;
use App\Domain\Payout\PayoutBuilder;
use App\Domain\Payout\TechnicianRateData;
use App\Domain\Rate\RateContext;
use App\Domain\Rate\RateResolver;
use App\Domain\Sla\SlaCalculator;
use App\Domain\Sla\SlaEvaluator;
use App\Domain\Sla\SlaWindows;
use App\Test\TestCase\Domain\DianoraRateCard;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The technician side of the ledger, and the margin that falls out of it.
 */
final class PayoutBuilderTest extends TestCase
{
    private PayoutBuilder $payouts;
    private ChargeBuilder $charges;
    private RateResolver $resolver;
    private SlaCalculator $calculator;
    private SlaEvaluator $evaluator;
    private DateTimeImmutable $received;

    protected function setUp(): void
    {
        $this->payouts = new PayoutBuilder();
        $this->charges = new ChargeBuilder();
        $this->resolver = new RateResolver();
        $this->calculator = new SlaCalculator();
        $this->evaluator = new SlaEvaluator();
        $this->received = new DateTimeImmutable('2026-07-01 09:00:00');
    }

    private function vendorSide(
        string $jobType,
        WarrantyScope $scope,
        ?float $sizeInch,
        float $closedAfterHours,
        ?float $travelKm = null,
    ): ChargeSet {
        $closed = $this->received->modify(sprintf('+%d minutes', (int)round($closedAfterHours * 60)));

        $timing = $this->calculator->calculate(
            receivedAt: $this->received,
            firstContactAt: $this->received->modify('+30 minutes'),
            visitedAt: $closed,
            closedAt: $closed,
            holds: [],
            windows: new SlaWindows(2, 48, 48),
            now: $closed->modify('+1 hour'),
        );

        $rate = $this->resolver->resolve(
            new RateContext($jobType, $scope, sizeInch: $sizeInch),
            DianoraRateCard::items(),
        );

        return $this->charges->build(
            $rate,
            $this->evaluator->evaluate($timing, DianoraRateCard::slaRules(), $jobType, $scope),
            DianoraRateCard::terms(),
            $timing,
            $travelKm,
        );
    }

    /**
     * A flat-rate contractor on a 32" installation closed inside 24h.
     *
     * We bill the vendor Rs.400 (Rs.350 + Rs.50 incentive).
     * We pay the technician Rs.200 flat + their full Rs.50 incentive share.
     * Margin: Rs.150.
     */
    public function testFlatRateContractorWithFullBonusShare(): void
    {
        $vendorSide = $this->vendorSide(
            DianoraRateCard::JOB_INSTALLATION,
            WarrantyScope::NotApplicable,
            32,
            20,
        );

        $rate = new TechnicianRateData(
            id: 1,
            model: PayoutModel::FlatPerJob,
            flatAmount: Money::fromRupees(200),
            bonusSharePct: '100.00',
        );

        $lines = $this->payouts->build($vendorSide, $rate, DianoraRateCard::JOB_INSTALLATION);
        $complete = $vendorSide->withLines($lines);

        $this->assertSame(40_000, $complete->vendorReceivable()->paise);
        $this->assertSame(25_000, $complete->technicianPayable()->paise);
        $this->assertSame(15_000, $complete->grossMargin()->paise);
        $this->assertSame('₹150.00', $complete->grossMargin()->format());
    }

    /**
     * Withholding the incentive share removes the technician's reason to
     * close fast, and the margin absorbs it instead.
     */
    public function testWithholdingTheBonusShareShiftsItToMargin(): void
    {
        $vendorSide = $this->vendorSide(
            DianoraRateCard::JOB_INSTALLATION,
            WarrantyScope::NotApplicable,
            32,
            20,
        );

        $rate = new TechnicianRateData(
            id: 2,
            model: PayoutModel::FlatPerJob,
            flatAmount: Money::fromRupees(200),
            bonusSharePct: '0.00',
        );

        $lines = $this->payouts->build($vendorSide, $rate, DianoraRateCard::JOB_INSTALLATION);
        $complete = $vendorSide->withLines($lines);

        $this->assertCount(0, $complete->ofType(ChargeLineType::TechnicianBonusShare));
        $this->assertSame(20_000, $complete->technicianPayable()->paise);
        $this->assertSame(20_000, $complete->grossMargin()->paise);
    }

    /**
     * A percentage technician is paid on the service charge only — not on
     * the incentive, nor on travel reimbursement.
     *
     * 40% of the Rs.500 in-warranty service = Rs.200, plus half the Rs.75
     * incentive = Rs.37.50 (rounds to Rs.37.50 exactly at 50%).
     */
    public function testPercentageTechnicianIsPaidOnServiceChargeOnly(): void
    {
        $vendorSide = $this->vendorSide(
            DianoraRateCard::JOB_SERVICE,
            WarrantyScope::InWarranty,
            55,
            30,
            travelKm: 42.0,   // adds Rs.81 travel, which must NOT be in the basis
        );

        $rate = new TechnicianRateData(
            id: 3,
            model: PayoutModel::PctOfVendor,
            pctOfVendor: '40.00',
            bonusSharePct: '50.00',
        );

        $lines = $this->payouts->build($vendorSide, $rate, DianoraRateCard::JOB_SERVICE, travelKm: 42.0);
        $complete = $vendorSide->withLines($lines);

        $base = $complete->ofType(ChargeLineType::TechnicianPayout)[0];
        $this->assertSame(20_000, $base->amount->paise, '40% of Rs.500, not of Rs.656');

        $share = $complete->ofType(ChargeLineType::TechnicianBonusShare)[0];
        $this->assertSame(3_750, $share->amount->paise, '50% of Rs.75');

        // Rs.656 in, Rs.237.50 out.
        $this->assertSame(65_600, $complete->vendorReceivable()->paise);
        $this->assertSame(23_750, $complete->technicianPayable()->paise);
        $this->assertSame(41_850, $complete->grossMargin()->paise);
    }

    /**
     * A salaried technician earns no per-job base, but still earns
     * incentives — otherwise there is nothing rewarding a fast close.
     */
    public function testSalariedTechnicianEarnsIncentivesButNoJobBase(): void
    {
        $vendorSide = $this->vendorSide(
            DianoraRateCard::JOB_SERVICE,
            WarrantyScope::InWarranty,
            32,
            20,
        );

        $rate = new TechnicianRateData(
            id: 4,
            model: PayoutModel::Salaried,
            monthlySalary: Money::fromRupees(18_000),
            bonusSharePct: '100.00',
        );

        $lines = $this->payouts->build($vendorSide, $rate, DianoraRateCard::JOB_SERVICE);
        $complete = $vendorSide->withLines($lines);

        $this->assertCount(0, $complete->ofType(ChargeLineType::TechnicianPayout));
        $this->assertSame(7_500, $complete->technicianPayable()->paise, 'The Rs.75 incentive only');
    }

    /**
     * When a late installation costs us Rs.50, penalty recovery decides
     * who actually absorbs it.
     *
     * At 100% recovery: we bill Rs.300, we pay Rs.200 - Rs.50 = Rs.150,
     * and the margin is unchanged at Rs.150 — the technician carries the
     * cost of their own delay.
     */
    public function testFullPenaltyRecoveryProtectsTheMargin(): void
    {
        $vendorSide = $this->vendorSide(
            DianoraRateCard::JOB_INSTALLATION,
            WarrantyScope::NotApplicable,
            32,
            60,   // late
        );

        $rate = new TechnicianRateData(
            id: 5,
            model: PayoutModel::FlatPerJob,
            flatAmount: Money::fromRupees(200),
            penaltyRecoveryPct: '100.00',
        );

        $lines = $this->payouts->build($vendorSide, $rate, DianoraRateCard::JOB_INSTALLATION);
        $complete = $vendorSide->withLines($lines);

        $recovery = $complete->ofType(ChargeLineType::TechnicianPenaltyRecovery)[0];
        $this->assertSame(-5_000, $recovery->amount->paise, 'Reduces what we owe them');
        $this->assertSame(Ledger::TechnicianPayable, $recovery->ledger);

        $this->assertSame(30_000, $complete->vendorReceivable()->paise);
        $this->assertSame(15_000, $complete->technicianPayable()->paise);
        $this->assertSame(15_000, $complete->grossMargin()->paise, 'Margin protected');
    }

    /**
     * With no recovery configured, the same delay comes straight out of
     * our margin instead.
     */
    public function testWithoutRecoveryTheDelayCostsUsTheMargin(): void
    {
        $vendorSide = $this->vendorSide(
            DianoraRateCard::JOB_INSTALLATION,
            WarrantyScope::NotApplicable,
            32,
            60,
        );

        $rate = new TechnicianRateData(
            id: 6,
            model: PayoutModel::FlatPerJob,
            flatAmount: Money::fromRupees(200),
            penaltyRecoveryPct: '0.00',
        );

        $lines = $this->payouts->build($vendorSide, $rate, DianoraRateCard::JOB_INSTALLATION);
        $complete = $vendorSide->withLines($lines);

        $this->assertCount(0, $complete->ofType(ChargeLineType::TechnicianPenaltyRecovery));
        $this->assertSame(20_000, $complete->technicianPayable()->paise);
        $this->assertSame(10_000, $complete->grossMargin()->paise, 'Rs.50 came out of our margin');
    }

    /**
     * The technician's travel allowance is a separate rate from the
     * vendor's reimbursement, and the spread between the two is ours.
     *
     * Vendor pays Rs.3/km beyond 15km; we pay the technician Rs.2/km from
     * the first kilometre. On a 42km job: Rs.81 in, Rs.84 out — which on
     * this leg is a small loss, and precisely the sort of thing you only
     * notice if the two rates are modelled separately.
     */
    public function testTechnicianTravelIsRatedIndependentlyOfTheVendor(): void
    {
        $vendorSide = $this->vendorSide(
            DianoraRateCard::JOB_SERVICE,
            WarrantyScope::InWarranty,
            55,
            30,
            travelKm: 42.0,
        );

        $rate = new TechnicianRateData(
            id: 7,
            model: PayoutModel::FlatPerJob,
            flatAmount: Money::fromRupees(250),
            travelFreeKm: 0.0,
            travelRatePerKm: Money::fromRupees(2),
            bonusSharePct: '100.00',
        );

        $lines = $this->payouts->build($vendorSide, $rate, DianoraRateCard::JOB_SERVICE, travelKm: 42.0);
        $complete = $vendorSide->withLines($lines);

        $technicianTravel = array_values(array_filter(
            $complete->ofType(ChargeLineType::Travel),
            static fn ($l): bool => $l->ledger === Ledger::TechnicianPayable,
        ));

        $this->assertCount(1, $technicianTravel);
        $this->assertSame(8_400, $technicianTravel[0]->amount->paise, '42km @ Rs.2');

        // Rs.656 in; Rs.250 + Rs.75 + Rs.84 = Rs.409 out.
        $this->assertSame(40_900, $complete->technicianPayable()->paise);
        $this->assertSame(24_700, $complete->grossMargin()->paise);
    }

    /**
     * A rate restricted to particular job types does not pay out on others,
     * which is how a panel specialist gets a different rate to a general
     * technician.
     */
    public function testRateScopedToJobTypesDoesNotPayOnOthers(): void
    {
        $vendorSide = $this->vendorSide(
            DianoraRateCard::JOB_SERVICE,
            WarrantyScope::InWarranty,
            32,
            20,
        );

        $rate = new TechnicianRateData(
            id: 8,
            model: PayoutModel::FlatPerJob,
            flatAmount: Money::fromRupees(600),
            appliesToJobTypes: [DianoraRateCard::JOB_PANEL],
        );

        $lines = $this->payouts->build($vendorSide, $rate, DianoraRateCard::JOB_SERVICE);

        $this->assertSame([], $lines);
    }

    /**
     * The full out-of-warranty picture across all four ledgers.
     *
     *   Rs.1500 collected from the customer
     * - Rs.150  royalty owed back to the vendor
     * - Rs.500  technician payout
     * = Rs.850  margin
     */
    public function testOutOfWarrantyJobNetsAcrossAllFourLedgers(): void
    {
        $vendorSide = $this->vendorSide(
            DianoraRateCard::JOB_SERVICE,
            WarrantyScope::OutOfWarranty,
            70,
            30,
        );

        $rate = new TechnicianRateData(
            id: 9,
            model: PayoutModel::PctOfVendor,
            pctOfVendor: '33.33',
        );

        $lines = $this->payouts->build($vendorSide, $rate, DianoraRateCard::JOB_SERVICE);
        $complete = $vendorSide->withLines($lines);

        $this->assertSame(150_000, $complete->customerCollection()->paise);
        $this->assertSame(15_000, $complete->vendorPayable()->paise);
        $this->assertSame(49_995, $complete->technicianPayable()->paise, '33.33% of Rs.1500');
        $this->assertTrue($complete->vendorReceivable()->isZero());
        $this->assertSame(85_005, $complete->grossMargin()->paise);

        $summary = $complete->summary();
        $this->assertSame('₹850.05', $summary['gross_margin']['formatted']);
    }
}
