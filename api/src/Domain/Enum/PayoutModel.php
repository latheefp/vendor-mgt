<?php
declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * How a technician is compensated for a job.
 *
 * A superset of the arrangements that actually occur in this trade, so
 * that moving one person from contract to salary is a new rate row rather
 * than a code change.
 */
enum PayoutModel: string
{
    /** A fixed amount per closed job. */
    case FlatPerJob = 'flat_per_job';

    /** A percentage of the service charge the job earned. */
    case PctOfCompany = 'pct_of_company';

    /**
     * A monthly wage. Produces no per-job base line — the salary is paid
     * on the payout run — but incentives and travel still accrue per job,
     * which is what keeps a salaried technician motivated to close fast.
     */
    case Salaried = 'salaried';

    public function hasPerJobBase(): bool
    {
        return $this !== self::Salaried;
    }

    public function label(): string
    {
        return match ($this) {
            self::FlatPerJob => 'Flat per job',
            self::PctOfCompany => 'Percentage of service charge',
            self::Salaried => 'Salaried',
        };
    }
}
