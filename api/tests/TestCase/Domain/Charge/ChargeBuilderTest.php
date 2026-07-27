<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Charge;

use App\Domain\Charge\ChargeBuilder;
use App\Domain\Charge\SpareUsage;
use App\Domain\Enum\ChargeLineType;
use App\Domain\Enum\Ledger;
use App\Domain\Enum\Payer;
use App\Domain\Enum\WarrantyScope;
use App\Domain\Money;
use App\Domain\Rate\RateContext;
use App\Domain\Rate\RateResolver;
use App\Domain\Sla\SlaCalculator;
use App\Domain\Sla\SlaEvaluator;
use App\Domain\Sla\SlaTiming;
use App\Domain\Sla\SlaWindows;
use App\Test\TestCase\Domain\DianoraRateCard;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Complete jobs, priced end to end against the signed agreement.
 *
 * These are the tests that would catch a regression before it reached an
 * invoice, so they assert whole ledger totals rather than individual
 * helper calls.
 */
final class ChargeBuilderTest extends TestCase
{
    private ChargeBuilder $builder;
    private RateResolver $resolver;
    private SlaCalculator $calculator;
    private SlaEvaluator $evaluator;
    private DateTimeImmutable $received;

    protected function setUp(): void
    {
        $this->builder = new ChargeBuilder();
        $this->resolver = new RateResolver();
        $this->calculator = new SlaCalculator();
        $this->evaluator = new SlaEvaluator();
        $this->received = new DateTimeImmutable('2026-07-01 09:00:00');
    }

    private function timingClosedAfter(float $hours): SlaTiming
    {
        $closed = $this->received->modify(sprintf('+%d minutes', (int)round($hours * 60)));

        return $this->calculator->calculate(
            receivedAt: $this->received,
            firstContactAt: $this->received->modify('+30 minutes'),
            visitedAt: $closed,
            closedAt: $closed,
            holds: [],
            windows: new SlaWindows(2, 48, 48),
            now: $closed->modify('+1 hour'),
        );
    }

    /**
     * Prices a job the way the application will: resolve, measure,
     * evaluate, build.
     *
     * @param list<SpareUsage> $spares
     */
    private function priceJob(
        string $jobType,
        WarrantyScope $scope,
        ?float $sizeInch,
        float $closedAfterHours,
        ?float $travelKm = null,
        array $spares = [],
    ) {
        $timing = $this->timingClosedAfter($closedAfterHours);

        $rate = $this->resolver->resolve(
            new RateContext($jobType, $scope, sizeInch: $sizeInch),
            DianoraRateCard::items(),
        );

        $matched = $this->evaluator->evaluate(
            $timing,
            DianoraRateCard::slaRules(),
            $jobType,
            $scope,
        );

        return $this->builder->build(
            $rate,
            $matched,
            DianoraRateCard::terms(),
            $timing,
            $travelKm,
            $spares,
        );
    }

    /**
     * A 32" installation closed in 20 hours, 8km from the centre.
     *
     *   Rs.350 installation 24"-43"
     * + Rs.50  closed within 24h
     * + Rs.0   travel (inside the 15km free radius)
     * = Rs.400 receivable from the vendor
     */
    public function testInstallationWithFastCloseBonus(): void
    {
        $charges = $this->priceJob(
            DianoraRateCard::JOB_INSTALLATION,
            WarrantyScope::NotApplicable,
            sizeInch: 32,
            closedAfterHours: 20,
            travelKm: 8.0,
        );

        $this->assertSame(40_000, $charges->vendorReceivable()->paise);
        $this->assertTrue($charges->customerCollection()->isZero());
        $this->assertTrue($charges->vendorPayable()->isZero());
        $this->assertCount(2, $charges->lines, 'Base plus incentive, no travel line');
    }

    /**
     * The same installation delivered late.
     *
     *   Rs.350 installation
     * - Rs.50  after 48h
     * = Rs.300
     */
    public function testLateInstallationIsDeducted(): void
    {
        $charges = $this->priceJob(
            DianoraRateCard::JOB_INSTALLATION,
            WarrantyScope::NotApplicable,
            sizeInch: 32,
            closedAfterHours: 60,
        );

        $this->assertSame(30_000, $charges->vendorReceivable()->paise);

        $penalties = $charges->ofType(ChargeLineType::SlaPenalty);
        $this->assertCount(1, $penalties);
        $this->assertSame(-5_000, $penalties[0]->amount->paise, 'Penalty must be negative');
        $this->assertSame(Ledger::VendorReceivable, $penalties[0]->ledger);
    }

    /**
     * A 55" in-warranty service closed in 30 hours, 42km away.
     *
     *   Rs.500    service in warranty 45"-85"
     * + Rs.75     closed within 48h
     * + Rs.81     travel: (42 - 15) = 27km @ Rs.3
     * = Rs.656 receivable
     */
    public function testInWarrantyServiceWithTravelBeyondTheFreeRadius(): void
    {
        $charges = $this->priceJob(
            DianoraRateCard::JOB_SERVICE,
            WarrantyScope::InWarranty,
            sizeInch: 55,
            closedAfterHours: 30,
            travelKm: 42.0,
        );

        $travel = $charges->ofType(ChargeLineType::Travel);
        $this->assertCount(1, $travel);
        $this->assertSame(8_100, $travel[0]->amount->paise, '27km @ Rs.3 = Rs.81');
        $this->assertSame('27.00', $travel[0]->quantity);

        $this->assertSame(65_600, $charges->vendorReceivable()->paise);
        $this->assertSame('₹656.00', $charges->vendorReceivable()->format());
    }

    /**
     * Travel inside the free radius produces no line at all, rather than a
     * zero-value one that clutters the invoice.
     */
    public function testTravelInsideTheFreeRadiusProducesNoLine(): void
    {
        $charges = $this->priceJob(
            DianoraRateCard::JOB_SERVICE,
            WarrantyScope::InWarranty,
            sizeInch: 55,
            closedAfterHours: 30,
            travelKm: 15.0,
        );

        $this->assertCount(0, $charges->ofType(ChargeLineType::Travel));
    }

    /**
     * The most involved case on the card: a 70" out-of-warranty repair with
     * a spare part.
     *
     * Collected from the customer:
     *   Rs.1500   out-of-warranty service 65"-85"
     * + Rs.2000   spare part cost
     * + Rs.240    12% margin on the spare (inside the 10-15% band)
     * = Rs.3740
     *
     * Owed back to the vendor:
     *   Rs.150    10% royalty on the SERVICE charge only
     *
     * Note the royalty is Rs.150, not Rs.374. Clause 8 says "royalty on the
     * service charges collected", and spares are governed separately by
     * clause 6 — so the spare and its margin are excluded.
     */
    public function testOutOfWarrantyRepairWithSpareAndRoyalty(): void
    {
        $spare = new SpareUsage(
            id: 77,
            partNo: 'DN-PANEL-70',
            name: 'Backlight strip',
            quantity: 1,
            unitCost: Money::fromRupees(2000),
            marginPct: '12.00',
            chargedTo: Payer::Customer,
        );

        $charges = $this->priceJob(
            DianoraRateCard::JOB_SERVICE,
            WarrantyScope::OutOfWarranty,
            sizeInch: 70,
            closedAfterHours: 30,
            travelKm: 10.0,
            spares: [$spare],
        );

        $this->assertSame(374_000, $charges->customerCollection()->paise, 'Rs.3740 collected at the door');
        $this->assertSame(15_000, $charges->vendorPayable()->paise, 'Rs.150 royalty on service only');
        $this->assertTrue($charges->vendorReceivable()->isZero(), 'Nothing billed to the vendor');

        // Cost and margin are separate lines so the margin stays visible.
        $this->assertSame(200_000, $charges->ofType(ChargeLineType::SpareCost)[0]->amount->paise);
        $this->assertSame(24_000, $charges->ofType(ChargeLineType::SpareMargin)[0]->amount->paise);

        // Before paying the technician, the job is worth Rs.3740 - Rs.150.
        $this->assertSame(359_000, $charges->grossMargin()->paise);
    }

    /**
     * The royalty basis is recorded in the snapshot, because the reading of
     * clause 8 is an interpretation and the vendor may challenge it.
     */
    public function testRoyaltySnapshotRecordsItsBasis(): void
    {
        $charges = $this->priceJob(
            DianoraRateCard::JOB_SERVICE,
            WarrantyScope::OutOfWarranty,
            sizeInch: 32,
            closedAfterHours: 20,
        );

        $royalty = $charges->ofType(ChargeLineType::VendorRoyalty)[0];

        $this->assertSame('service_charge_only', $royalty->snapshot['basis']);
        $this->assertSame(50_000, $royalty->snapshot['basis_paise']);
        $this->assertSame(['spare_cost', 'spare_margin'], $royalty->snapshot['excludes']);
        $this->assertSame(5_000, $royalty->amount->paise);
    }

    /**
     * In-warranty spares are supplied by the vendor and produce no money —
     * only a return obligation, which lives on the ticket_spares row.
     */
    public function testInWarrantySparesProduceNoChargeLines(): void
    {
        $spare = new SpareUsage(
            id: 88,
            partNo: 'DN-BOARD-43',
            name: 'Main board',
            quantity: 1,
            unitCost: Money::fromRupees(1800),
            marginPct: '0.00',
            chargedTo: Payer::Vendor,
        );

        $charges = $this->priceJob(
            DianoraRateCard::JOB_SERVICE,
            WarrantyScope::InWarranty,
            sizeInch: 43,
            closedAfterHours: 20,
            spares: [$spare],
        );

        $this->assertCount(0, $charges->ofType(ChargeLineType::SpareCost));
        $this->assertCount(0, $charges->ofType(ChargeLineType::SpareMargin));
        // Rs.400 service + Rs.75 incentive
        $this->assertSame(47_500, $charges->vendorReceivable()->paise);
    }

    /**
     * A job that ran long in wall-clock time but was mostly spent waiting
     * on the customer still earns its incentive.
     */
    public function testHeldTicketStillEarnsItsIncentive(): void
    {
        $closed = $this->received->modify('+60 hours');

        $timing = $this->calculator->calculate(
            receivedAt: $this->received,
            firstContactAt: $this->received->modify('+30 minutes'),
            visitedAt: $this->received->modify('+3 hours'),
            closedAt: $closed,
            holds: [
                new \App\Domain\Sla\HoldPeriod(
                    startedAt: $this->received->modify('+4 hours'),
                    endedAt: $this->received->modify('+40 hours'),
                    reasonCode: 'spare_awaited',
                ),
            ],
            windows: new SlaWindows(2, 48, 48),
            now: $closed->modify('+1 hour'),
        );

        $rate = $this->resolver->resolve(
            new RateContext(DianoraRateCard::JOB_SERVICE, WarrantyScope::InWarranty, sizeInch: 32),
            DianoraRateCard::items(),
        );

        $matched = $this->evaluator->evaluate(
            $timing,
            DianoraRateCard::slaRules(),
            DianoraRateCard::JOB_SERVICE,
            WarrantyScope::InWarranty,
        );

        $charges = $this->builder->build($rate, $matched, DianoraRateCard::terms(), $timing);

        // Net 24h, so the Rs.75 incentive stands: Rs.400 + Rs.75.
        $this->assertSame(47_500, $charges->vendorReceivable()->paise);
        $this->assertSame(24.0, $timing->close->netHours());
    }

    /**
     * Every line has to be traceable back to whatever priced it.
     */
    public function testEveryLineCarriesItsProvenance(): void
    {
        $charges = $this->priceJob(
            DianoraRateCard::JOB_INSTALLATION,
            WarrantyScope::NotApplicable,
            sizeInch: 32,
            closedAfterHours: 20,
        );

        $base = $charges->ofType(ChargeLineType::Base)[0];
        $this->assertSame(1, $base->rateCardItemId());
        $this->assertNotEmpty($base->snapshot['rate']);

        $bonus = $charges->ofType(ChargeLineType::SlaBonus)[0];
        $this->assertSame(1, $bonus->slaRuleId());
        $this->assertSame('install_close_24', $bonus->snapshot['rule']['code']);
    }

    /**
     * Adjustments are the only sanctioned way to change a frozen ticket,
     * and they must carry a reason.
     */
    public function testAdjustmentCarriesItsReason(): void
    {
        $adjustment = $this->builder->adjustment(
            Ledger::VendorReceivable,
            Money::fromRupees(50)->negate(),
            'Vendor disputed the travel distance; agreed 20km by email 2026-07-15',
            authorisedByUserId: 3,
        );

        $this->assertSame(ChargeLineType::Adjustment, $adjustment->type);
        $this->assertSame(-5_000, $adjustment->amount->paise);
        $this->assertSame(3, $adjustment->snapshot['authorised_by_user_id']);
        $this->assertStringContainsString('disputed', $adjustment->snapshot['reason']);
    }

    /**
     * The clause 6 margin band is enforceable, not advisory.
     */
    public function testSpareMarginBandIsEnforceable(): void
    {
        $terms = DianoraRateCard::terms();

        $this->assertTrue($terms->isSpareMarginPermitted('10.00'));
        $this->assertTrue($terms->isSpareMarginPermitted('12.50'));
        $this->assertTrue($terms->isSpareMarginPermitted('15.00'));
        $this->assertFalse($terms->isSpareMarginPermitted('9.99'));
        $this->assertFalse($terms->isSpareMarginPermitted('20.00'));
    }
}
