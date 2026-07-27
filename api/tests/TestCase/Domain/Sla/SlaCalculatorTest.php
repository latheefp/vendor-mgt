<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Sla;

use App\Domain\Sla\HoldPeriod;
use App\Domain\Sla\SlaCalculator;
use App\Domain\Sla\SlaWindows;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The SLA clock, with particular attention to pause accounting — the part
 * that decides whether we absorb penalties for delays that were not ours.
 */
final class SlaCalculatorTest extends TestCase
{
    private SlaCalculator $calculator;
    private DateTimeImmutable $received;
    private SlaWindows $windows;

    protected function setUp(): void
    {
        $this->calculator = new SlaCalculator();
        $this->received = new DateTimeImmutable('2026-07-01 09:00:00');
        // The Dianora windows: 2h contact, 48h visit, 48h close.
        $this->windows = new SlaWindows(contactHours: 2, visitHours: 48, closeHours: 48);
    }

    private function at(string $modify): DateTimeImmutable
    {
        return $this->received->modify($modify);
    }

    public function testCleanRunMeetsEveryWindow(): void
    {
        $timing = $this->calculator->calculate(
            receivedAt: $this->received,
            firstContactAt: $this->at('+45 minutes'),
            visitedAt: $this->at('+6 hours'),
            closedAt: $this->at('+7 hours'),
            holds: [],
            windows: $this->windows,
            now: $this->at('+8 hours'),
        );

        $this->assertTrue($timing->contact->isMet);
        $this->assertTrue($timing->visit->isMet);
        $this->assertTrue($timing->close->isMet);
        $this->assertTrue($timing->allMet());
        $this->assertSame(0, $timing->totalPausedMinutes);
        $this->assertSame(7.0, $timing->close->netHours());
    }

    /**
     * Clause 1 gives us 2 hours to make contact. Three hours is a breach.
     */
    public function testLateFirstContactBreachesTheTwoHourWindow(): void
    {
        $timing = $this->calculator->calculate(
            receivedAt: $this->received,
            firstContactAt: $this->at('+3 hours'),
            visitedAt: $this->at('+5 hours'),
            closedAt: $this->at('+6 hours'),
            holds: [],
            windows: $this->windows,
            now: $this->at('+7 hours'),
        );

        $this->assertFalse($timing->contact->isMet);
        $this->assertTrue($timing->close->isMet);
        $this->assertFalse($timing->allMet());
        $this->assertSame(['hours_to_contact'], array_map(
            static fn ($m): string => $m->value,
            $timing->breachedMetrics(),
        ));
    }

    /**
     * The core of the design. A job that took 60 wall-clock hours but spent
     * 24 of them waiting on a customer who was away has a NET elapsed time
     * of 36 hours, and so is inside the 48h window.
     *
     * Without this, we would eat a penalty for someone else's holiday.
     */
    public function testHoldTimeIsSubtractedFromTheClosureClock(): void
    {
        $timing = $this->calculator->calculate(
            receivedAt: $this->received,
            firstContactAt: $this->at('+1 hour'),
            visitedAt: $this->at('+5 hours'),
            closedAt: $this->at('+60 hours'),
            holds: [
                new HoldPeriod(
                    startedAt: $this->at('+6 hours'),
                    endedAt: $this->at('+30 hours'),
                    reasonCode: 'customer_unavailable',
                ),
            ],
            windows: $this->windows,
            now: $this->at('+61 hours'),
        );

        $this->assertSame(3_600, $timing->close->rawMinutes);      // 60h wall clock
        $this->assertSame(1_440, $timing->close->pausedMinutes);   // 24h on hold
        $this->assertSame(2_160, $timing->close->netMinutes);      // 36h net
        $this->assertSame(36.0, $timing->close->netHours());
        $this->assertTrue($timing->close->isMet, 'Net 36h is inside the 48h window');
    }

    /**
     * A hold only excuses the windows it actually overlaps.
     *
     * Here the customer went away AFTER the engineer had already visited.
     * That delay excuses the closure clock but must not retroactively
     * excuse the visit clock, which had already been running late. One
     * delay must not excuse two breaches.
     */
    public function testHoldAfterTheVisitDoesNotExcuseTheVisitClock(): void
    {
        $timing = $this->calculator->calculate(
            receivedAt: $this->received,
            firstContactAt: $this->at('+1 hour'),
            visitedAt: $this->at('+50 hours'),   // already past the 48h visit window
            closedAt: $this->at('+80 hours'),
            holds: [
                new HoldPeriod(
                    startedAt: $this->at('+52 hours'),
                    endedAt: $this->at('+76 hours'),
                    reasonCode: 'spare_awaited',
                ),
            ],
            windows: $this->windows,
            now: $this->at('+81 hours'),
        );

        // The hold began after the visit, so it contributes nothing here.
        $this->assertSame(0, $timing->visit->pausedMinutes);
        $this->assertSame(50.0, $timing->visit->netHours());
        $this->assertFalse($timing->visit->isMet, 'Visit was genuinely late');

        // But it does reduce the closure clock: 80h - 24h = 56h.
        $this->assertSame(1_440, $timing->close->pausedMinutes);
        $this->assertSame(56.0, $timing->close->netHours());
        $this->assertFalse($timing->close->isMet, 'Still over 48h even after the pause');
    }

    /**
     * Only the intersection counts. A hold that starts before the clock
     * even began must not hand back time we never spent waiting.
     */
    public function testOnlyTheOverlappingPortionOfAHoldCounts(): void
    {
        $timing = $this->calculator->calculate(
            receivedAt: $this->received,
            firstContactAt: null,
            visitedAt: null,
            closedAt: $this->at('+10 hours'),
            holds: [
                // Starts 5h before receipt, ends 4h after: only 4h overlap.
                new HoldPeriod(
                    startedAt: $this->at('-5 hours'),
                    endedAt: $this->at('+4 hours'),
                ),
            ],
            windows: $this->windows,
            now: $this->at('+11 hours'),
        );

        $this->assertSame(600, $timing->close->rawMinutes);
        $this->assertSame(240, $timing->close->pausedMinutes, 'Only the 4h inside the window');
        $this->assertSame(360, $timing->close->netMinutes);
    }

    /**
     * A hold nobody closed is still running, so it counts up to now rather
     * than being ignored.
     */
    public function testOpenHoldCountsUpToTheMomentOfMeasurement(): void
    {
        $timing = $this->calculator->calculate(
            receivedAt: $this->received,
            firstContactAt: $this->at('+1 hour'),
            visitedAt: null,
            closedAt: null,
            holds: [
                new HoldPeriod(startedAt: $this->at('+10 hours'), endedAt: null),
            ],
            windows: $this->windows,
            now: $this->at('+30 hours'),
        );

        $this->assertSame(1_200, $timing->close->pausedMinutes, '20h of open hold');
        $this->assertSame(10.0, $timing->close->netHours());
        $this->assertNull($timing->close->isMet, 'Still inside the window and still open');
    }

    /**
     * An untouched ticket must show as breached once its window expires,
     * not sit undecided forever.
     */
    public function testUntouchedTicketBreachesOnceTheWindowExpires(): void
    {
        $timing = $this->calculator->calculate(
            receivedAt: $this->received,
            firstContactAt: null,
            visitedAt: null,
            closedAt: null,
            holds: [],
            windows: $this->windows,
            now: $this->at('+72 hours'),
        );

        $this->assertFalse($timing->contact->isMet);
        $this->assertFalse($timing->visit->isMet);
        $this->assertFalse($timing->close->isMet);
        $this->assertNull($timing->close->achievedAt);
    }

    /**
     * The deadline itself slides out by the pause, so the dispatch board
     * sorts by a due date that reflects reality.
     */
    public function testDueDateShiftsByThePauseDuration(): void
    {
        $timing = $this->calculator->calculate(
            receivedAt: $this->received,
            firstContactAt: $this->at('+1 hour'),
            visitedAt: $this->at('+4 hours'),
            closedAt: null,
            holds: [
                new HoldPeriod(startedAt: $this->at('+5 hours'), endedAt: $this->at('+17 hours')),
            ],
            windows: $this->windows,
            now: $this->at('+20 hours'),
        );

        // 48h window + 12h paused = due 60h after receipt.
        $this->assertSame(
            $this->at('+60 hours')->format('Y-m-d H:i:s'),
            $timing->close->dueAt->format('Y-m-d H:i:s'),
        );
    }

    /**
     * Exactly on the boundary is met, not breached — "within 48 hrs".
     */
    public function testExactlyOnTheBoundaryCounts(): void
    {
        $timing = $this->calculator->calculate(
            receivedAt: $this->received,
            firstContactAt: $this->at('+2 hours'),
            visitedAt: $this->at('+48 hours'),
            closedAt: $this->at('+48 hours'),
            holds: [],
            windows: $this->windows,
            now: $this->at('+49 hours'),
        );

        $this->assertTrue($timing->contact->isMet);
        $this->assertTrue($timing->visit->isMet);
        $this->assertTrue($timing->close->isMet);
    }

    /**
     * Several holds accumulate.
     */
    public function testMultipleHoldsAccumulate(): void
    {
        $timing = $this->calculator->calculate(
            receivedAt: $this->received,
            firstContactAt: $this->at('+1 hour'),
            visitedAt: $this->at('+3 hours'),
            closedAt: $this->at('+70 hours'),
            holds: [
                new HoldPeriod($this->at('+4 hours'), $this->at('+16 hours')),  // 12h
                new HoldPeriod($this->at('+20 hours'), $this->at('+32 hours')), // 12h
            ],
            windows: $this->windows,
            now: $this->at('+71 hours'),
        );

        $this->assertSame(1_440, $timing->close->pausedMinutes);
        $this->assertSame(46.0, $timing->close->netHours());
        $this->assertTrue($timing->close->isMet);
    }
}
