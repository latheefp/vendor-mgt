<?php
declare(strict_types=1);

namespace App\Domain\Sla;

use App\Domain\Money;

/**
 * A rule that fired, together with the measurement that triggered it.
 *
 * The measured hours are captured alongside the rule so an incentive line
 * on an invoice can state its own justification: "closed in 21.4h, within
 * the 24h window". That sentence is what settles a query without anyone
 * having to reopen the ticket.
 */
final readonly class MatchedSlaRule
{
    public function __construct(
        public SlaRuleData $rule,
        public MetricResult $measurement,
    ) {
    }

    public function amount(): Money
    {
        return $this->rule->signedAmount();
    }

    /**
     * Invoice-ready wording.
     */
    public function description(): string
    {
        return sprintf(
            '%s (%s, actual %.1fh)',
            $this->rule->label,
            $this->rule->describeThreshold(),
            $this->measurement->netHours(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'rule' => [
                'id' => $this->rule->id,
                'code' => $this->rule->code,
                'label' => $this->rule->label,
                'kind' => $this->rule->kind->value,
                'metric' => $this->rule->metric->value,
                'comparator' => $this->rule->comparator->value,
                'threshold_from_hours' => $this->rule->thresholdFromHours,
                'threshold_to_hours' => $this->rule->thresholdToHours,
                'amount_paise' => $this->rule->amount->paise,
                'priority' => $this->rule->priority,
                'is_stackable' => $this->rule->isStackable,
            ],
            'measurement' => $this->measurement->toArray(),
            'signed_amount_paise' => $this->amount()->paise,
        ];
    }
}
