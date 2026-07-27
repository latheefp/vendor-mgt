<?php
declare(strict_types=1);

namespace App\Domain\Rate;

use App\Domain\Enum\WarrantyScope;

/**
 * Everything needed to price one job.
 *
 * Built from the ticket at the moment of rating and then frozen into the
 * charge's calc_snapshot, so a disputed amount can be replayed against
 * exactly the inputs that produced it.
 */
final readonly class RateContext
{
    public function __construct(
        public string $jobTypeCode,
        public WarrantyScope $warrantyScope,
        public ?string $productCategoryCode = null,
        public ?float $sizeInch = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'job_type' => $this->jobTypeCode,
            'warranty_scope' => $this->warrantyScope->value,
            'product_category' => $this->productCategoryCode,
            'size_inch' => $this->sizeInch,
        ];
    }

    public function describe(): string
    {
        $parts = [
            $this->jobTypeCode,
            $this->warrantyScope->value,
        ];

        if ($this->productCategoryCode !== null) {
            $parts[] = $this->productCategoryCode;
        }

        $parts[] = $this->sizeInch !== null
            ? sprintf('%g"', $this->sizeInch)
            : 'size unknown';

        return implode(' / ', $parts);
    }
}
