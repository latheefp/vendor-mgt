<?php
declare(strict_types=1);

namespace App\Domain\Sla;

use App\Domain\Enum\SlaComparator;
use App\Domain\Enum\SlaKind;
use App\Domain\Enum\SlaMetric;
use App\Domain\Enum\WarrantyScope;
use App\Domain\Money;

/**
 * One SLA incentive or deduction, as configured on a rate card.
 *
 * The four rules on the Dianora card map onto this shape exactly:
 *
 *   installation closed <= 24h          bonus   +Rs.50
 *   installation closed >  48h          penalty -Rs.50
 *   in-warranty service closed <= 48h   bonus   +Rs.75
 *   in-warranty service closed 48-72h   bonus   +Rs.50
 *
 * `amount` is always a positive magnitude; `kind` supplies the sign. A
 * penalty stored with a negative amount would otherwise pay out.
 */
final readonly class SlaRuleData
{
    public function __construct(
        public int $id,
        public string $code,
        public string $label,
        public SlaKind $kind,
        public SlaMetric $metric,
        public SlaComparator $comparator,
        public Money $amount,
        public ?float $thresholdFromHours = null,
        public ?float $thresholdToHours = null,
        /** null = every job type @var list<string>|null */
        public ?array $appliesToJobTypes = null,
        /** null = any warranty scope */
        public ?WarrantyScope $warrantyScope = null,
        public int $priority = 100,
        /** false = this rule ends matching for its metric */
        public bool $isStackable = false,
        public bool $isActive = true,
    ) {
    }

    public function appliesToJobType(string $jobTypeCode): bool
    {
        return $this->appliesToJobTypes === null
            || in_array($jobTypeCode, $this->appliesToJobTypes, true);
    }

    public function appliesToScope(WarrantyScope $scope): bool
    {
        return $this->warrantyScope === null || $this->warrantyScope === $scope;
    }

    /**
     * Does the measured elapsed time fall in this rule's band?
     */
    public function matchesHours(float $netHours): bool
    {
        return $this->comparator->matches(
            $netHours,
            $this->thresholdFromHours,
            $this->thresholdToHours,
        );
    }

    /**
     * The signed amount this rule contributes.
     */
    public function signedAmount(): Money
    {
        return $this->kind === SlaKind::Penalty
            ? $this->amount->absolute()->negate()
            : $this->amount->absolute();
    }

    public function describeThreshold(): string
    {
        return match ($this->comparator) {
            SlaComparator::Lte => sprintf('within %gh', $this->thresholdToHours),
            SlaComparator::Gt => sprintf('after %gh', $this->thresholdFromHours),
            SlaComparator::Between => sprintf(
                'between %gh and %gh',
                $this->thresholdFromHours,
                $this->thresholdToHours,
            ),
        };
    }
}
