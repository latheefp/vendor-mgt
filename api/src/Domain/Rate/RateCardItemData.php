<?php
declare(strict_types=1);

namespace App\Domain\Rate;

use App\Domain\Enum\Payer;
use App\Domain\Enum\WarrantyScope;
use App\Domain\Money;

/**
 * One priced line from a rate card, as the resolver sees it.
 *
 * A framework-free snapshot rather than an ORM entity, so the resolver
 * can be unit tested against the numbers in the signed agreement without
 * a database, and so nothing in the pricing path can lazy-load.
 *
 * Size bands are an explicit inch range on the item, not a shared lookup.
 * They have to be: the Dianora card bands installation as 24-43 and
 * 45-65, in-warranty service as 24-43 and 45-85, and out-of-warranty
 * service as 24-43, 45-55 and 65-85. No single band table survives that.
 */
final readonly class RateCardItemData
{
    public function __construct(
        public int $id,
        public string $jobTypeCode,
        public WarrantyScope $warrantyScope,
        public Money $amount,
        public Payer $payer,
        public string $label,
        /** null = applies to every product category on the card */
        public ?string $productCategoryCode = null,
        /** Inclusive lower bound in inches; null with sizeMaxInch = size independent */
        public ?float $sizeMinInch = null,
        /** Inclusive upper bound in inches */
        public ?float $sizeMaxInch = null,
        public int $priority = 100,
        public bool $isActive = true,
    ) {
    }

    public function isSizeBanded(): bool
    {
        return $this->sizeMinInch !== null || $this->sizeMaxInch !== null;
    }

    /**
     * Does this item cover the given screen size?
     *
     * A size-banded item cannot price a ticket whose size is unknown. That
     * is a hard no rather than a fallback, because guessing the band means
     * guessing the amount.
     */
    public function coversSize(?float $sizeInch): bool
    {
        if (!$this->isSizeBanded()) {
            return true;
        }

        if ($sizeInch === null) {
            return false;
        }

        if ($this->sizeMinInch !== null && $sizeInch < $this->sizeMinInch) {
            return false;
        }

        if ($this->sizeMaxInch !== null && $sizeInch > $this->sizeMaxInch) {
            return false;
        }

        return true;
    }

    public function coversCategory(?string $categoryCode): bool
    {
        return $this->productCategoryCode === null
            || $this->productCategoryCode === $categoryCode;
    }

    /**
     * How tightly this item is targeted. Higher wins, so an item naming a
     * category and a band beats a catch-all.
     */
    public function specificity(): int
    {
        return ($this->productCategoryCode !== null ? 10 : 0)
            + ($this->isSizeBanded() ? 10 : 0);
    }

    /**
     * Width of the size band, used to break ties between two equally
     * specific items — the narrower band is the more deliberate rate.
     * Open-ended bands sort last.
     */
    public function bandWidth(): float
    {
        if ($this->sizeMinInch !== null && $this->sizeMaxInch !== null) {
            return $this->sizeMaxInch - $this->sizeMinInch;
        }

        return $this->isSizeBanded() ? 10_000.0 : INF;
    }

    public function describeBand(): string
    {
        if (!$this->isSizeBanded()) {
            return 'any size';
        }

        if ($this->sizeMinInch !== null && $this->sizeMaxInch !== null) {
            return sprintf('%g"-%g"', $this->sizeMinInch, $this->sizeMaxInch);
        }

        return $this->sizeMinInch !== null
            ? sprintf('%g" and above', $this->sizeMinInch)
            : sprintf('up to %g"', $this->sizeMaxInch);
    }
}
