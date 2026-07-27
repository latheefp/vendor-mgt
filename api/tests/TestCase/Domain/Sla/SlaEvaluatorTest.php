<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Sla;

use App\Domain\Enum\SlaKind;
use App\Domain\Enum\WarrantyScope;
use App\Domain\Money;
use App\Domain\Sla\SlaCalculator;
use App\Domain\Sla\SlaEvaluator;
use App\Domain\Sla\SlaTiming;
use App\Domain\Sla\SlaWindows;
use App\Test\TestCase\Domain\DianoraRateCard;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The four SLA rules on the Dianora card, exercised across the whole
 * range of closure times.
 */
final class SlaEvaluatorTest extends TestCase
{
    private SlaEvaluator $evaluator;
    private SlaCalculator $calculator;
    private DateTimeImmutable $received;

    protected function setUp(): void
    {
        $this->evaluator = new SlaEvaluator();
        $this->calculator = new SlaCalculator();
        $this->received = new DateTimeImmutable('2026-07-01 09:00:00');
    }

    private function closedAfter(float $hours): SlaTiming
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
     * "Additional Rs.50 (if case closed within 24 hr)".
     */
    public function testInstallationClosedInsideTwentyFourHoursEarnsFifty(): void
    {
        $matched = $this->evaluator->evaluate(
            $this->closedAfter(20),
            DianoraRateCard::slaRules(),
            DianoraRateCard::JOB_INSTALLATION,
            WarrantyScope::NotApplicable,
        );

        $this->assertCount(1, $matched);
        $this->assertSame('install_close_24', $matched[0]->rule->code);
        $this->assertSame(SlaKind::Bonus, $matched[0]->rule->kind);
        $this->assertTrue($matched[0]->amount()->equals(Money::fromRupees(50)));
    }

    /**
     * "24" to 65" Installation after 48 Hrs will deduct Rs.50" — and the
     * deduction has to come through as a NEGATIVE amount.
     */
    public function testInstallationAfterFortyEightHoursDeductsFifty(): void
    {
        $matched = $this->evaluator->evaluate(
            $this->closedAfter(60),
            DianoraRateCard::slaRules(),
            DianoraRateCard::JOB_INSTALLATION,
            WarrantyScope::NotApplicable,
        );

        $this->assertCount(1, $matched);
        $this->assertSame('install_late_48', $matched[0]->rule->code);
        $this->assertSame(SlaKind::Penalty, $matched[0]->rule->kind);
        $this->assertSame(-5_000, $matched[0]->amount()->paise);
    }

    /**
     * Between 24h and 48h the card offers an installation neither a bonus
     * nor a penalty. The dead band is real and must stay empty.
     */
    public function testInstallationBetweenTwentyFourAndFortyEightHoursIsNeutral(): void
    {
        foreach ([25.0, 36.0, 48.0] as $hours) {
            $matched = $this->evaluator->evaluate(
                $this->closedAfter($hours),
                DianoraRateCard::slaRules(),
                DianoraRateCard::JOB_INSTALLATION,
                WarrantyScope::NotApplicable,
            );

            $this->assertCount(0, $matched, sprintf('Expected no rule to fire at %.1fh', $hours));
        }
    }

    /**
     * "Additional Service Benefit for closing the complaint within 48 Hrs
     * — Rs.75".
     */
    public function testInWarrantyServiceClosedInsideFortyEightHoursEarnsSeventyFive(): void
    {
        $matched = $this->evaluator->evaluate(
            $this->closedAfter(30),
            DianoraRateCard::slaRules(),
            DianoraRateCard::JOB_SERVICE,
            WarrantyScope::InWarranty,
        );

        $this->assertCount(1, $matched);
        $this->assertSame('service_close_48', $matched[0]->rule->code);
        $this->assertSame(7_500, $matched[0]->amount()->paise);
    }

    /**
     * "After 48 Hrs & within 72 Hrs — Rs.50".
     *
     * This is the case the priority ordering exists for: BOTH the 48h and
     * the 72h rules are configured against hours_to_close, and only the
     * one that actually applies may fire.
     */
    public function testInWarrantyServiceClosedInTheSecondBandEarnsFiftyOnly(): void
    {
        $matched = $this->evaluator->evaluate(
            $this->closedAfter(60),
            DianoraRateCard::slaRules(),
            DianoraRateCard::JOB_SERVICE,
            WarrantyScope::InWarranty,
        );

        $this->assertCount(1, $matched, 'Exactly one closure incentive may fire');
        $this->assertSame('service_close_72', $matched[0]->rule->code);
        $this->assertSame(5_000, $matched[0]->amount()->paise);
    }

    /**
     * The 48h boundary belongs to the tighter band, so a job closed at
     * exactly 48h pays Rs.75 and not Rs.50. The bands tile the number line
     * with no gap and no overlap.
     */
    public function testTheFortyEightHourBoundaryPaysTheHigherIncentive(): void
    {
        $matched = $this->evaluator->evaluate(
            $this->closedAfter(48),
            DianoraRateCard::slaRules(),
            DianoraRateCard::JOB_SERVICE,
            WarrantyScope::InWarranty,
        );

        $this->assertCount(1, $matched);
        $this->assertSame('service_close_48', $matched[0]->rule->code);
        $this->assertSame(7_500, $matched[0]->amount()->paise);
    }

    /**
     * Past 72h the card offers nothing at all.
     */
    public function testInWarrantyServicePastSeventyTwoHoursEarnsNothing(): void
    {
        $matched = $this->evaluator->evaluate(
            $this->closedAfter(80),
            DianoraRateCard::slaRules(),
            DianoraRateCard::JOB_SERVICE,
            WarrantyScope::InWarranty,
        );

        $this->assertCount(0, $matched);
    }

    /**
     * The service incentives are scoped to in-warranty work. An
     * out-of-warranty job the customer paid for does not earn one.
     */
    public function testOutOfWarrantyServiceEarnsNoIncentive(): void
    {
        $matched = $this->evaluator->evaluate(
            $this->closedAfter(20),
            DianoraRateCard::slaRules(),
            DianoraRateCard::JOB_SERVICE,
            WarrantyScope::OutOfWarranty,
        );

        $this->assertCount(0, $matched);
    }

    /**
     * Rules are filtered by job type, so an installation incentive never
     * attaches itself to a service call.
     */
    public function testRulesDoNotLeakAcrossJobTypes(): void
    {
        $matched = $this->evaluator->evaluate(
            $this->closedAfter(20),
            DianoraRateCard::slaRules(),
            DianoraRateCard::JOB_PANEL,
            WarrantyScope::InWarranty,
        );

        $this->assertCount(0, $matched, 'Panel work has no SLA rules on this card');
    }

    /**
     * An incentive on an unfinished job would be inventing income.
     */
    public function testAnOpenTicketEarnsNothingYet(): void
    {
        $timing = $this->calculator->calculate(
            receivedAt: $this->received,
            firstContactAt: $this->received->modify('+30 minutes'),
            visitedAt: null,
            closedAt: null,
            holds: [],
            windows: new SlaWindows(2, 48, 48),
            now: $this->received->modify('+10 hours'),
        );

        $matched = $this->evaluator->evaluate(
            $timing,
            DianoraRateCard::slaRules(),
            DianoraRateCard::JOB_INSTALLATION,
            WarrantyScope::NotApplicable,
        );

        $this->assertCount(0, $matched);
    }

    /**
     * Pause time flows through to the incentive. A job that took 60 wall
     * hours but only 20 net hours earns the 24h installation bonus — which
     * is the entire commercial point of tracking holds.
     */
    public function testPausedTimeCanEarnBackAnIncentive(): void
    {
        $closed = $this->received->modify('+60 hours');

        $timing = $this->calculator->calculate(
            receivedAt: $this->received,
            firstContactAt: $this->received->modify('+30 minutes'),
            visitedAt: $closed,
            closedAt: $closed,
            holds: [
                new \App\Domain\Sla\HoldPeriod(
                    startedAt: $this->received->modify('+2 hours'),
                    endedAt: $this->received->modify('+42 hours'),
                    reasonCode: 'customer_unavailable',
                ),
            ],
            windows: new SlaWindows(2, 48, 48),
            now: $closed->modify('+1 hour'),
        );

        $this->assertSame(20.0, $timing->close->netHours());

        $matched = $this->evaluator->evaluate(
            $timing,
            DianoraRateCard::slaRules(),
            DianoraRateCard::JOB_INSTALLATION,
            WarrantyScope::NotApplicable,
        );

        $this->assertCount(1, $matched);
        $this->assertSame('install_close_24', $matched[0]->rule->code);
        $this->assertSame(5_000, $matched[0]->amount()->paise);
    }

    /**
     * The description must justify itself on the invoice.
     */
    public function testMatchedRuleDescribesItsOwnJustification(): void
    {
        $matched = $this->evaluator->evaluate(
            $this->closedAfter(21.4),
            DianoraRateCard::slaRules(),
            DianoraRateCard::JOB_INSTALLATION,
            WarrantyScope::NotApplicable,
        );

        $this->assertMatchesRegularExpression(
            '/Installation closed within 24 hours \(within 24h, actual 21\.4h\)/',
            $matched[0]->description(),
        );
    }
}
