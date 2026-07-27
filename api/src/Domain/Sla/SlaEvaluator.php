<?php
declare(strict_types=1);

namespace App\Domain\Sla;

use App\Domain\Enum\WarrantyScope;

/**
 * Decides which SLA rules fired for a ticket.
 *
 * Rules are grouped by metric and walked in `priority` order. Within a
 * metric the first non-stackable match wins and stops the walk. That is
 * precisely what keeps the Dianora card's "closed within 48h" (+Rs.75)
 * and "closed after 48h and within 72h" (+Rs.50) from both paying out on
 * the same job: they share the hours_to_close metric, the tighter band is
 * given the lower priority, and matching stops there.
 *
 * A rule flagged stackable does not stop the walk, which leaves room for
 * a future agreement that pays, say, a closure incentive and a separate
 * first-contact incentive on the same clock.
 *
 * A window still open and not yet breached is skipped entirely. Paying an
 * incentive on a job that has not closed would be inventing income.
 */
final readonly class SlaEvaluator
{
    /**
     * @param list<SlaRuleData> $rules
     * @return list<MatchedSlaRule>
     */
    public function evaluate(
        SlaTiming $timing,
        array $rules,
        string $jobTypeCode,
        WarrantyScope $warrantyScope,
    ): array {
        $matched = [];

        foreach ($this->groupByMetric($rules) as $metricRules) {
            foreach ($metricRules as $rule) {
                if (!$this->isApplicable($rule, $jobTypeCode, $warrantyScope)) {
                    continue;
                }

                $measurement = $timing->forMetric($rule->metric);

                // Undecided window: no incentive and no deduction yet.
                if ($measurement->isPending()) {
                    continue;
                }

                if (!$rule->matchesHours($measurement->netHours())) {
                    continue;
                }

                $matched[] = new MatchedSlaRule($rule, $measurement);

                if (!$rule->isStackable) {
                    break;
                }
            }
        }

        return $matched;
    }

    private function isApplicable(
        SlaRuleData $rule,
        string $jobTypeCode,
        WarrantyScope $warrantyScope,
    ): bool {
        return $rule->isActive
            && $rule->appliesToJobType($jobTypeCode)
            && $rule->appliesToScope($warrantyScope);
    }

    /**
     * Rules bucketed by metric, each bucket ordered by priority then id so
     * evaluation is deterministic even when two rules share a priority.
     *
     * @param list<SlaRuleData> $rules
     * @return array<string, list<SlaRuleData>>
     */
    private function groupByMetric(array $rules): array
    {
        $grouped = [];
        foreach ($rules as $rule) {
            $grouped[$rule->metric->value][] = $rule;
        }

        foreach ($grouped as &$bucket) {
            usort(
                $bucket,
                static fn (SlaRuleData $a, SlaRuleData $b): int
                    => $a->priority <=> $b->priority ?: $a->id <=> $b->id,
            );
        }

        return $grouped;
    }
}
