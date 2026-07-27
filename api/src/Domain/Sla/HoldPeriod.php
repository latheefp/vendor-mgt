<?php
declare(strict_types=1);

namespace App\Domain\Sla;

use DateTimeImmutable;

/**
 * One stretch of time during which the SLA clock was stopped.
 *
 * Holds are the difference between a fair SLA and one we lose money on.
 * "All service and installation should be closed within 48 hrs" is not
 * achievable when the customer is away for a week or the panel is on
 * back-order, and neither delay is ours. Each hold is recorded, emailed
 * to the vendor, and subtracted from the elapsed time.
 *
 * An open hold (no `endedAt`) is treated as running up to the moment of
 * measurement, so a ticket still on hold does not silently accrue
 * billable delay.
 */
final readonly class HoldPeriod
{
    public function __construct(
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $endedAt = null,
        public ?string $reasonCode = null,
    ) {
    }

    public function isOpen(): bool
    {
        return $this->endedAt === null;
    }

    /**
     * Minutes this hold overlaps the window [$from, $to].
     *
     * Only the intersection counts. A hold that began before the engineer
     * visited should reduce the time-to-visit clock, but a hold that began
     * afterwards must not — otherwise one delay would excuse two breaches.
     */
    public function overlapMinutes(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if ($to <= $from) {
            return 0;
        }

        $holdEnd = $this->endedAt ?? $to;

        $start = max($this->startedAt->getTimestamp(), $from->getTimestamp());
        $end = min($holdEnd->getTimestamp(), $to->getTimestamp());

        if ($end <= $start) {
            return 0;
        }

        return intdiv($end - $start, 60);
    }
}
