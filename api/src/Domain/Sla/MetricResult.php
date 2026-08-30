<?php
declare(strict_types=1);

namespace App\Domain\Sla;

use App\Domain\Enum\SlaMetric;
use DateTimeImmutable;

/**
 * How one SLA clock actually ran.
 *
 * Both the raw and the net elapsed time are kept. The company's own system
 * will show the raw figure, so when we claim an incentive on the net one
 * we need to be able to show the difference and the holds that justify it
 * in the same breath.
 */
final readonly class MetricResult
{
    public function __construct(
        public SlaMetric $metric,
        /** Wall-clock minutes from receipt to the achieving event (or now). */
        public int $rawMinutes,
        /** Minutes the clock was stopped inside this window. */
        public int $pausedMinutes,
        /** rawMinutes - pausedMinutes, floored at zero. */
        public int $netMinutes,
        /** The agreed window, in hours. */
        public float $windowHours,
        /** When this window expires, shifted out by any pauses. */
        public DateTimeImmutable $dueAt,
        /** When the window was satisfied; null if it has not been yet. */
        public ?DateTimeImmutable $achievedAt,
        /**
         * true  = satisfied inside the window
         * false = breached
         * null  = still open and not yet breached, so undecided
         */
        public ?bool $isMet,
    ) {
    }

    /**
     * Net elapsed hours — the figure SLA rules are evaluated against.
     */
    public function netHours(): float
    {
        return $this->netMinutes / 60;
    }

    public function rawHours(): float
    {
        return $this->rawMinutes / 60;
    }

    public function isBreached(): bool
    {
        return $this->isMet === false;
    }

    public function isPending(): bool
    {
        return $this->isMet === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric->value,
            'raw_minutes' => $this->rawMinutes,
            'paused_minutes' => $this->pausedMinutes,
            'net_minutes' => $this->netMinutes,
            'net_hours' => round($this->netHours(), 4),
            'window_hours' => $this->windowHours,
            'due_at' => $this->dueAt->format('Y-m-d H:i:s'),
            'achieved_at' => $this->achievedAt?->format('Y-m-d H:i:s'),
            'is_met' => $this->isMet,
        ];
    }
}
