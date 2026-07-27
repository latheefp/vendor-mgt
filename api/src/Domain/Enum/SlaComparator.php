<?php
declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * How an SLA rule's thresholds are tested against an elapsed-hours value.
 *
 * Bound conventions are fixed here once so that adjacent bands cannot
 * both match or both miss. `Between` is exclusive at the bottom and
 * inclusive at the top, which is exactly what makes "within 48h" and
 * "after 48h and within 72h" tile the number line without a gap at 48.
 */
enum SlaComparator: string
{
    /** hours <= thresholdTo */
    case Lte = 'lte';

    /** hours > thresholdFrom */
    case Gt = 'gt';

    /** thresholdFrom < hours <= thresholdTo */
    case Between = 'between';

    public function label(): string
    {
        return match ($this) {
            self::Lte => 'within',
            self::Gt => 'after',
            self::Between => 'between',
        };
    }

    /**
     * @param float $hours Elapsed hours, already net of SLA pauses.
     */
    public function matches(float $hours, ?float $from, ?float $to): bool
    {
        return match ($this) {
            self::Lte => $to !== null && $hours <= $to,
            self::Gt => $from !== null && $hours > $from,
            self::Between => $from !== null && $to !== null && $hours > $from && $hours <= $to,
        };
    }
}
