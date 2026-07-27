<?php
declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * The clocks an SLA rule can be written against.
 *
 * All three run from the moment the vendor handed us the job, not from
 * when the row was created — an email import can lag an assignment by
 * hours and we do not get those hours back.
 */
enum SlaMetric: string
{
    /** Clause 1: contact the customer within 2 hours. */
    case HoursToContact = 'hours_to_contact';

    /** Clause 2: engineer on site within 48 hours. */
    case HoursToVisit = 'hours_to_visit';

    /** Clause 3: job closed within 48 hours. Carries the incentives. */
    case HoursToClose = 'hours_to_close';

    public function label(): string
    {
        return match ($this) {
            self::HoursToContact => 'Hours to first contact',
            self::HoursToVisit => 'Hours to site visit',
            self::HoursToClose => 'Hours to closure',
        };
    }
}
