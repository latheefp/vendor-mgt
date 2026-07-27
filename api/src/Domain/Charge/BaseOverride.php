<?php
declare(strict_types=1);

namespace App\Domain\Charge;

use App\Domain\Enum\Payer;
use App\Domain\Money;

/**
 * A base service charge set by hand instead of read off the rate card.
 *
 * The card is the default and stays the default. This exists for the case
 * the resolver cannot answer at all: the Dianora card prices
 * out-of-warranty service for 24-43", 45-55" and 65-85", so a 60" set has
 * no agreed line and never will until someone renegotiates. Before this,
 * such a ticket closed with no ledger and no way to produce one.
 *
 * Two things make it defensible rather than a hole in the pricing:
 *
 *   - a reason is mandatory, and it is what prints on the invoice line
 *   - the rate the card WOULD have produced is kept in the snapshot
 *     alongside the manual figure, so the override is visible as a
 *     decision somebody made rather than as an ordinary charge
 *
 * The payer is deliberately part of this. Amount alone is not enough to
 * write a charge line: who pays decides which ledger it lands on, whether
 * a royalty is due, and whether cash has to be collected at the door.
 */
final readonly class BaseOverride
{
    public function __construct(
        public Money $amount,
        public string $reason,
        /**
         * Null means "whoever the rate card said". Only usable when the
         * resolver actually found an item; with no item there is nothing
         * to inherit from and the caller must say.
         */
        public ?Payer $payer = null,
        public ?int $authorisedByUserId = null,
    ) {
    }

    public function withPayer(Payer $payer): self
    {
        return new self($this->amount, $this->reason, $payer, $this->authorisedByUserId);
    }

    /**
     * What appears on the invoice. Prefixed so a manual figure is never
     * mistaken for a card rate by whoever reads the document.
     */
    public function description(): string
    {
        return sprintf('Service charge (agreed manually) - %s', $this->reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'manual' => true,
            'amount_paise' => $this->amount->paise,
            'reason' => $this->reason,
            'payer' => $this->payer?->value,
            'authorised_by_user_id' => $this->authorisedByUserId,
        ];
    }
}
