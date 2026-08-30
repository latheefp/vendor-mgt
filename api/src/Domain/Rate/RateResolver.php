<?php
declare(strict_types=1);

namespace App\Domain\Rate;

use App\Domain\Exception\RateNotFoundException;
use App\Domain\Exception\UnrateableTicketException;

/**
 * Picks the rate card item that prices a given job.
 *
 * Deliberately boring and completely deterministic. Given the same
 * context and the same card it must always return the same item, because
 * the alternative is an invoice that cannot be reproduced.
 *
 * Selection order:
 *   1. hard filters — job type, warranty scope, category, size band
 *   2. most specific wins (named category and/or an explicit band)
 *   3. narrower size band wins
 *   4. lower `priority` wins (the manual override)
 *   5. lower id wins, purely so step 4 ties still resolve the same way
 *
 * If nothing matches it throws. See RateNotFoundException for why that is
 * the right behaviour rather than falling back to a nearby band.
 */
final readonly class RateResolver
{
    /**
     * @param list<RateCardItemData> $items Items belonging to ONE rate card.
     * @throws \App\Domain\Exception\UnrateableTicketException
     * @throws \App\Domain\Exception\RateNotFoundException
     */
    public function resolve(
        RateContext $context,
        array $items,
        ?int $rateCardId = null,
    ): ResolvedRate {
        // A ticket whose warranty status nobody has established cannot be
        // priced, because scope decides who gets billed — not just how much.
        if (!$context->warrantyScope->isRateable()) {
            throw new UnrateableTicketException(
                'Warranty scope is still unknown, so it is not possible to tell '
                . 'whether the company or the customer should be billed. Confirm '
                . 'the warranty status against the serial number first.',
                $context,
            );
        }

        $candidates = $this->candidates($context, $items);

        if ($candidates === []) {
            throw new RateNotFoundException($context, $rateCardId, count($items));
        }

        usort($candidates, $this->comparator());

        $winner = array_shift($candidates);

        return new ResolvedRate($winner, $context, array_values($candidates));
    }

    /**
     * Every item that could legitimately price this job.
     *
     * @param list<RateCardItemData> $items
     * @return list<RateCardItemData>
     */
    public function candidates(RateContext $context, array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn (RateCardItemData $item): bool => $item->isActive
                && $item->jobTypeCode === $context->jobTypeCode
                && $item->warrantyScope === $context->warrantyScope
                && $item->coversCategory($context->productCategoryCode)
                && $item->coversSize($context->sizeInch),
        ));
    }

    /**
     * @return callable(RateCardItemData, RateCardItemData): int
     */
    private function comparator(): callable
    {
        return static function (RateCardItemData $a, RateCardItemData $b): int {
            return $b->specificity() <=> $a->specificity()
                ?: $a->bandWidth() <=> $b->bandWidth()
                ?: $a->priority <=> $b->priority
                ?: $a->id <=> $b->id;
        };
    }
}
