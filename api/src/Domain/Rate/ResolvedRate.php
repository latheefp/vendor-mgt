<?php
declare(strict_types=1);

namespace App\Domain\Rate;

use App\Domain\Enum\Ledger;
use App\Domain\Enum\Payer;
use App\Domain\Money;

/**
 * The outcome of pricing one job: which item won, at what amount, and
 * who pays.
 *
 * `alternatives` carries the other items that also matched. It exists so
 * that when a rate looks wrong the desk can see what else was in the
 * running instead of re-deriving the resolver's reasoning by hand.
 */
final readonly class ResolvedRate
{
    /**
     * @param list<RateCardItemData> $alternatives
     */
    public function __construct(
        public RateCardItemData $item,
        public RateContext $context,
        public array $alternatives = [],
    ) {
    }

    public function amount(): Money
    {
        return $this->item->amount;
    }

    public function payer(): Payer
    {
        return $this->item->payer;
    }

    public function ledger(): Ledger
    {
        return $this->item->payer->ledger();
    }

    /**
     * Invoice-ready wording. Uses the label copied verbatim from the
     * agreement so what we bill reads the same as what was signed.
     */
    public function description(): string
    {
        return $this->item->label;
    }

    /**
     * Frozen onto the charge line. Records both the decision and the
     * inputs, so the number can be explained months later without
     * anyone's memory being involved.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'context' => $this->context->toArray(),
            'matched_item' => [
                'id' => $this->item->id,
                'label' => $this->item->label,
                'job_type' => $this->item->jobTypeCode,
                'warranty_scope' => $this->item->warrantyScope->value,
                'product_category' => $this->item->productCategoryCode,
                'size_band' => $this->item->describeBand(),
                'amount_paise' => $this->item->amount->paise,
                'payer' => $this->item->payer->value,
                'specificity' => $this->item->specificity(),
                'priority' => $this->item->priority,
            ],
            'alternatives' => array_map(
                static fn (RateCardItemData $i): array => [
                    'id' => $i->id,
                    'label' => $i->label,
                    'size_band' => $i->describeBand(),
                    'amount_paise' => $i->amount->paise,
                ],
                $this->alternatives,
            ),
        ];
    }
}
