<?php
declare(strict_types=1);

namespace App\Domain\Exception;

use App\Domain\Rate\RateContext;
use RuntimeException;

/**
 * No rate card item covers the job in front of us.
 *
 * Thrown, never swallowed. A missing rate is almost always a real gap in
 * the agreement rather than a bug, and the Dianora card has one: its
 * out-of-warranty bands run 24-43, 45-55 and 65-85, so a 60" panel job
 * has no price at all (and neither does a 44" set anywhere on the card).
 *
 * Falling back to "the nearest band" would quietly invent a number and
 * bill a customer for it. Failing loudly puts the ticket in front of the
 * desk, who can get the rate confirmed by email — which is the only form
 * of confirmation clause 11 recognises anyway.
 */
final class RateNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly RateContext $context,
        public readonly ?int $rateCardId = null,
        public readonly int $candidatesConsidered = 0,
    ) {
        parent::__construct(sprintf(
            'No active rate card item matches %s%s. %d item(s) were considered. '
            . 'This is usually a gap in the vendor agreement rather than a bug — '
            . 'get the rate confirmed by email and add it to the rate card.',
            $context->describe(),
            $rateCardId !== null ? sprintf(' on rate card #%d', $rateCardId) : '',
            $this->candidatesConsidered,
        ));
    }
}
