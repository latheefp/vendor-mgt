<?php
declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Whether a matched SLA rule pays us or costs us.
 *
 * Rule amounts are always stored as a positive magnitude; this enum
 * supplies the sign at calculation time. That way a data-entry mistake
 * cannot produce a "penalty" that quietly pays out.
 */
enum SlaKind: string
{
    case Bonus = 'bonus';
    case Penalty = 'penalty';

    public function sign(): int
    {
        return match ($this) {
            self::Bonus => 1,
            self::Penalty => -1,
        };
    }

    public function chargeLineType(): ChargeLineType
    {
        return match ($this) {
            self::Bonus => ChargeLineType::SlaBonus,
            self::Penalty => ChargeLineType::SlaPenalty,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Bonus => 'Incentive',
            self::Penalty => 'Deduction',
        };
    }
}
