<?php
declare(strict_types=1);

namespace App\Domain\Sla;

use App\Domain\Enum\SlaMetric;

/**
 * All three SLA clocks for one ticket.
 *
 * Frozen into the charge snapshot at closure. Reports read the stored
 * result rather than recalculating, so last month's SLA numbers do not
 * move when someone edits a hold today.
 */
final readonly class SlaTiming
{
    public function __construct(
        public MetricResult $contact,
        public MetricResult $visit,
        public MetricResult $close,
        public int $totalPausedMinutes,
    ) {
    }

    public function forMetric(SlaMetric $metric): MetricResult
    {
        return match ($metric) {
            SlaMetric::HoursToContact => $this->contact,
            SlaMetric::HoursToVisit => $this->visit,
            SlaMetric::HoursToClose => $this->close,
        };
    }

    /**
     * @return list<MetricResult>
     */
    public function all(): array
    {
        return [$this->contact, $this->visit, $this->close];
    }

    /**
     * Did we honour every window that has been decided so far?
     */
    public function allMet(): bool
    {
        foreach ($this->all() as $result) {
            if ($result->isBreached()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<SlaMetric>
     */
    public function breachedMetrics(): array
    {
        $breached = [];
        foreach ($this->all() as $result) {
            if ($result->isBreached()) {
                $breached[] = $result->metric;
            }
        }

        return $breached;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_paused_minutes' => $this->totalPausedMinutes,
            'contact' => $this->contact->toArray(),
            'visit' => $this->visit->toArray(),
            'close' => $this->close->toArray(),
        ];
    }
}
