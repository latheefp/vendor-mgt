<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain;

use App\Domain\Charge\AgreementTerms;
use App\Domain\Charge\ChargeBuilder;
use App\Domain\Charge\SpareUsage;
use App\Domain\Enum\ChargeLineType;
use App\Domain\Enum\Payer;
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
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The terms that differ per company, and the fact that changing one
 * changes the money.
 *
 * Dianora is the only company on the system, so every one of these values
 * currently has exactly one setting and it would be easy to bake it in
 * without noticing. These tests exist to make that impossible: each one
 * runs the same job under two companies' terms and asserts the two
 * disagree. If a term is ever hardcoded, a test here stops paying
 * attention to its input and fails.
 */
final class CompanyPolicyTest extends TestCase
{
    private DateTimeImmutable $received;

    protected function setUp(): void
    {
        $this->received = new DateTimeImmutable('2026-07-01 09:00:00');
    }

    /**
     * Terms for a hypothetical second company, differing from Dianora on
     * every dial this file cares about.
     */
    private function otherCompanyTerms(
        string $bonusShare = '100.00',
        string $penaltyRecovery = '0.00',
        bool $royaltyOnSpares = false,
        string $royaltyPct = '10.00',
    ): AgreementTerms {
        return new AgreementTerms(
            id: 2,
            oowRoyaltyPct: $royaltyPct,
            spareMarginMinPct: '10.00',
            spareMarginMaxPct: '15.00',
            travelFreeKm: 15.0,
            travelRatePerKm: Money::fromRupees(3),
            slaWindows: new SlaWindows(2, 48, 48),
            creditLimit: Money::fromRupees(25_000),
            technicianBonusSharePct: $bonusShare,
            technicianPenaltyRecoveryPct: $penaltyRecovery,
            royaltyAppliesToSpares: $royaltyOnSpares,
        );
    }

    // -----------------------------------------------------------------
    // technician payout policy
    // -----------------------------------------------------------------

    /**
     * A technician with no individual arrangement follows the company's.
     *
     * This is the case that matters operationally: nobody wants to edit
     * forty technician rows because one company negotiated a different
     * incentive split.
     */
    public function testTechnicianWithNoOwnTermsFollowsCompanyPolicy(): void
    {
        $rate = new TechnicianRateData(
            id: 1,
            model: PayoutModel::FlatPerJob,
            flatAmount: Money::fromRupees(200),
            // Both null: "whatever this company says".
            bonusSharePct: null,
            penaltyRecoveryPct: null,
        );

        $generous = $rate->underTerms($this->otherCompanyTerms(bonusShare: '100.00'));
        $stingy = $rate->underTerms($this->otherCompanyTerms(bonusShare: '40.00'));

        $this->assertSame('100.00', $generous->bonusShare());
        $this->assertSame('40.00', $stingy->bonusShare());
    }

    /**
     * An individual arrangement survives a change in company policy.
     *
     * The distinction is the whole reason the column is nullable: a
     * technician on agreed personal terms must not be silently re-cut when
     * the company default moves.
     */
    public function testTechnicianOwnTermsOverrideCompanyPolicy(): void
    {
        $rate = new TechnicianRateData(
            id: 1,
            model: PayoutModel::FlatPerJob,
            flatAmount: Money::fromRupees(200),
            bonusSharePct: '80.00',
        );

        $bound = $rate->underTerms($this->otherCompanyTerms(bonusShare: '40.00'));

        $this->assertSame('80.00', $bound->bonusShare());
        // The company default still applies to the dial they did not set.
        $this->assertSame('0.00', $bound->penaltyRecovery());
    }

    /**
     * The share reaches the ledger, not just the DTO.
     */
    public function testCompanyPolicyChangesWhatTheTechnicianIsPaid(): void
    {
        $charges = $this->inWarrantyServiceClosedIn(hours: 20);

        $rate = new TechnicianRateData(
            id: 1,
            model: PayoutModel::FlatPerJob,
            flatAmount: Money::fromRupees(200),
            bonusSharePct: null,
        );

        $full = (new PayoutBuilder())->build(
            $charges,
            $rate->underTerms($this->otherCompanyTerms(bonusShare: '100.00')),
            DianoraRateCard::JOB_SERVICE,
        );

        $half = (new PayoutBuilder())->build(
            $charges,
            $rate->underTerms($this->otherCompanyTerms(bonusShare: '50.00')),
            DianoraRateCard::JOB_SERVICE,
        );

        $this->assertSame(7_500, $this->sumOf($full, ChargeLineType::TechnicianBonusShare));
        $this->assertSame(3_750, $this->sumOf($half, ChargeLineType::TechnicianBonusShare));
    }

    /**
     * Where the figure came from travels with the money.
     *
     * A payout queried a year later has to be explainable as either an
     * individual arrangement or the company policy of the day, and the
     * agreement may have changed since.
     */
    public function testPayoutRecordsWhetherTheShareWasPolicyOrOverride(): void
    {
        $policy = (new TechnicianRateData(id: 1, flatAmount: Money::fromRupees(200)))
            ->underTerms($this->otherCompanyTerms());

        $override = (new TechnicianRateData(id: 1, flatAmount: Money::fromRupees(200), bonusSharePct: '80.00'))
            ->underTerms($this->otherCompanyTerms());

        $this->assertSame('company_policy', $policy->toArray()['bonus_share_source']);
        $this->assertSame('technician_override', $override->toArray()['bonus_share_source']);
    }

    // -----------------------------------------------------------------
    // royalty basis
    // -----------------------------------------------------------------

    /**
     * Clause 8 says "service charges". Whether that reaches the margin on
     * a spare sold at the same visit is a reading of ambiguous wording,
     * and each company gets its own.
     */
    public function testRoyaltyBasisIsACompanyTerm(): void
    {
        $spares = [
            new SpareUsage(
                id: 1,
                partNo: 'PNL-43',
                name: 'Open cell 43"',
                quantity: 1,
                unitCost: Money::fromRupees(4_000),
                marginPct: '10.00',
                chargedTo: Payer::Customer,
            ),
        ];

        $narrow = $this->outOfWarrantyCharges($this->otherCompanyTerms(royaltyOnSpares: false), $spares);
        $broad = $this->outOfWarrantyCharges($this->otherCompanyTerms(royaltyOnSpares: true), $spares);

        // Out-of-warranty 43" service is Rs.500 on this card, so the narrow
        // reading takes 10% of Rs.500 = Rs.50.
        $this->assertSame(5_000, $this->sumOf($narrow, ChargeLineType::CompanyRoyalty));

        // The broad reading adds the customer-billed spare at cost + 10%:
        // Rs.4,000 + Rs.400 = Rs.4,400, making the basis Rs.4,900 and the
        // royalty Rs.490 — nearly ten times the narrow reading, which is
        // why this cannot be left to whoever writes the next importer.
        $this->assertSame(49_000, $this->sumOf($broad, ChargeLineType::CompanyRoyalty));
    }

    /**
     * An in-warranty part is supplied by the company and produces no
     * royalty basis under either reading — there is nothing collected to
     * take a percentage of.
     */
    public function testCompanySuppliedSparesNeverEnterTheRoyaltyBasis(): void
    {
        $spares = [
            new SpareUsage(
                id: 1,
                partNo: 'PNL-43',
                name: 'Open cell 43"',
                quantity: 1,
                unitCost: Money::fromRupees(4_000),
                chargedTo: Payer::Company,
            ),
        ];

        $broad = $this->outOfWarrantyCharges($this->otherCompanyTerms(royaltyOnSpares: true), $spares);

        // Rs.500 service charge only — the part was never collected for.
        $this->assertSame(5_000, $this->sumOf($broad, ChargeLineType::CompanyRoyalty));
    }

    /**
     * The royalty percentage itself is per-company, which is the whole
     * point of clause 8 being a column rather than a constant.
     */
    public function testRoyaltyPercentageIsPerCompany(): void
    {
        $ten = $this->outOfWarrantyCharges($this->otherCompanyTerms(royaltyPct: '10.00'));
        $fifteen = $this->outOfWarrantyCharges($this->otherCompanyTerms(royaltyPct: '15.00'));

        $this->assertSame(5_000, $this->sumOf($ten, ChargeLineType::CompanyRoyalty));
        $this->assertSame(7_500, $this->sumOf($fifteen, ChargeLineType::CompanyRoyalty));
    }

    // -----------------------------------------------------------------
    // SLA windows
    // -----------------------------------------------------------------

    /**
     * The same job, closed at the same moment, breaches under one
     * company's window and not under another's.
     */
    public function testSlaWindowsAreACompanyTerm(): void
    {
        $closed = $this->received->modify('+30 hours');
        $calculator = new SlaCalculator();

        $lenient = $calculator->calculate(
            receivedAt: $this->received,
            firstContactAt: $this->received->modify('+30 minutes'),
            visitedAt: $closed,
            closedAt: $closed,
            holds: [],
            windows: new SlaWindows(2, 48, 48),
            now: $closed,
        );

        $strict = $calculator->calculate(
            receivedAt: $this->received,
            firstContactAt: $this->received->modify('+30 minutes'),
            visitedAt: $closed,
            closedAt: $closed,
            holds: [],
            // A company that promises its customers same-day closure.
            windows: new SlaWindows(1, 24, 24),
            now: $closed,
        );

        $this->assertTrue($lenient->close->isMet, '30h closure meets a 48h window.');
        $this->assertFalse($strict->close->isMet, '30h closure breaches a 24h window.');
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * @return \App\Domain\Charge\ChargeSet
     */
    private function inWarrantyServiceClosedIn(float $hours)
    {
        $closed = $this->received->modify(sprintf('+%d minutes', (int)round($hours * 60)));

        $timing = (new SlaCalculator())->calculate(
            receivedAt: $this->received,
            firstContactAt: $this->received->modify('+30 minutes'),
            visitedAt: $closed,
            closedAt: $closed,
            holds: [],
            windows: new SlaWindows(2, 48, 48),
            now: $closed,
        );

        $rate = (new RateResolver())->resolve(
            new RateContext(DianoraRateCard::JOB_SERVICE, WarrantyScope::InWarranty, sizeInch: 43),
            DianoraRateCard::items(),
        );

        return (new ChargeBuilder())->build(
            $rate,
            (new SlaEvaluator())->evaluate(
                $timing,
                DianoraRateCard::slaRules(),
                DianoraRateCard::JOB_SERVICE,
                WarrantyScope::InWarranty,
            ),
            DianoraRateCard::terms(),
            $timing,
        );
    }

    /**
     * @param list<SpareUsage> $spares
     * @return \App\Domain\Charge\ChargeSet
     */
    private function outOfWarrantyCharges(AgreementTerms $terms, array $spares = [])
    {
        $closed = $this->received->modify('+20 hours');

        $timing = (new SlaCalculator())->calculate(
            receivedAt: $this->received,
            firstContactAt: $this->received->modify('+30 minutes'),
            visitedAt: $closed,
            closedAt: $closed,
            holds: [],
            windows: $terms->windows(),
            now: $closed,
        );

        $rate = (new RateResolver())->resolve(
            new RateContext(DianoraRateCard::JOB_SERVICE, WarrantyScope::OutOfWarranty, sizeInch: 43),
            DianoraRateCard::items(),
        );

        return (new ChargeBuilder())->build($rate, [], $terms, $timing, null, $spares);
    }

    private function sumOf(mixed $lines, ChargeLineType $type): int
    {
        $lines = is_array($lines) ? $lines : $lines->lines;

        $total = 0;
        foreach ($lines as $line) {
            if ($line->type === $type) {
                $total += $line->amount->paise;
            }
        }

        return $total;
    }
}
