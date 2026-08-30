<?php
declare(strict_types=1);

namespace App\Domain\Sla;

/**
 * The SLA windows from a company agreement, in hours.
 *
 * Defaults are the Dianora terms — contact within 2h (clause 1), engineer
 * on site within 48h (clause 2), job closed within 48h (clause 3) — but
 * they are only defaults. Every company gets their own row, which is the
 * whole reason these live in the database instead of in this file.
 */
final readonly class SlaWindows
{
    public function __construct(
        public float $contactHours = 2.0,
        public float $visitHours = 48.0,
        public float $closeHours = 48.0,
    ) {
    }

    /**
     * @return array<string, float>
     */
    public function toArray(): array
    {
        return [
            'contact_hours' => $this->contactHours,
            'visit_hours' => $this->visitHours,
            'close_hours' => $this->closeHours,
        ];
    }
}
