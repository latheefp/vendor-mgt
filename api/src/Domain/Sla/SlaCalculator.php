<?php
declare(strict_types=1);

namespace App\Domain\Sla;

use App\Domain\Enum\SlaMetric;
use DateTimeImmutable;

/**
 * Runs the three SLA clocks for a ticket.
 *
 * Two decisions here carry real money:
 *
 * 1. Every clock starts at `receivedAt` — the moment the company handed us
 *    the job — not at row creation. An email import can lag an assignment
 *    by hours and the agreement does not give those hours back.
 *
 * 2. A hold is only subtracted from a window it actually overlaps. If the
 *    customer went away after the engineer had already visited, that delay
 *    excuses the closure clock but not the visit clock. Subtracting every
 *    pause from every window would let one delay excuse three breaches,
 *    which is the kind of arithmetic that ends an agreement.
 */
final readonly class SlaCalculator
{
    /**
     * @param list<HoldPeriod> $holds
     */
    public function calculate(
        DateTimeImmutable $receivedAt,
        ?DateTimeImmutable $firstContactAt,
        ?DateTimeImmutable $visitedAt,
        ?DateTimeImmutable $closedAt,
        array $holds,
        SlaWindows $windows,
        ?DateTimeImmutable $now = null,
    ): SlaTiming {
        $now ??= new DateTimeImmutable();

        $contact = $this->measure(
            SlaMetric::HoursToContact,
            $receivedAt,
            $firstContactAt,
            $windows->contactHours,
            $holds,
            $now,
        );

        $visit = $this->measure(
            SlaMetric::HoursToVisit,
            $receivedAt,
            $visitedAt,
            $windows->visitHours,
            $holds,
            $now,
        );

        $close = $this->measure(
            SlaMetric::HoursToClose,
            $receivedAt,
            $closedAt,
            $windows->closeHours,
            $holds,
            $now,
        );

        return new SlaTiming($contact, $visit, $close, $this->totalPausedMinutes($holds, $now));
    }

    /**
     * @param list<HoldPeriod> $holds
     */
    private function measure(
        SlaMetric $metric,
        DateTimeImmutable $receivedAt,
        ?DateTimeImmutable $achievedAt,
        float $windowHours,
        array $holds,
        DateTimeImmutable $now,
    ): MetricResult {
        // An unachieved window is measured up to now, so a ticket sitting
        // untouched shows as breached rather than as pending forever.
        $measureTo = $achievedAt ?? $now;

        $rawMinutes = max(0, intdiv($measureTo->getTimestamp() - $receivedAt->getTimestamp(), 60));
        $pausedMinutes = $this->pausedMinutesWithin($holds, $receivedAt, $measureTo);
        $netMinutes = max(0, $rawMinutes - $pausedMinutes);

        // The deadline slides out by however long the clock was stopped.
        $dueAt = $receivedAt->modify(
            sprintf('+%d minutes', (int)round($windowHours * 60) + $pausedMinutes),
        );

        $windowMinutes = (int)round($windowHours * 60);

        if ($achievedAt !== null) {
            $isMet = $netMinutes <= $windowMinutes;
        } elseif ($netMinutes > $windowMinutes) {
            // Already past the deadline with nothing recorded: breached.
            $isMet = false;
        } else {
            // Still inside the window and still open — undecided.
            $isMet = null;
        }

        return new MetricResult(
            metric: $metric,
            rawMinutes: $rawMinutes,
            pausedMinutes: $pausedMinutes,
            netMinutes: $netMinutes,
            windowHours: $windowHours,
            dueAt: $dueAt,
            achievedAt: $achievedAt,
            isMet: $isMet,
        );
    }

    /**
     * Sum of hold time intersecting one window.
     *
     * @param list<HoldPeriod> $holds
     */
    private function pausedMinutesWithin(
        array $holds,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): int {
        $total = 0;
        foreach ($holds as $hold) {
            $total += $hold->overlapMinutes($from, $to);
        }

        return $total;
    }

    /**
     * @param list<HoldPeriod> $holds
     */
    private function totalPausedMinutes(array $holds, DateTimeImmutable $now): int
    {
        $total = 0;
        foreach ($holds as $hold) {
            $end = $hold->endedAt ?? $now;
            $total += max(0, intdiv($end->getTimestamp() - $hold->startedAt->getTimestamp(), 60));
        }

        return $total;
    }
}
