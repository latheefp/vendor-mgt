<?php
declare(strict_types=1);

namespace App\Domain\Exception;

use App\Domain\Rate\RateContext;
use RuntimeException;

/**
 * The ticket is missing something the pricing path cannot proceed without.
 *
 * Distinct from RateNotFoundException: there the agreement has a gap, here
 * our own data does. Unknown warranty scope is the common case, and it
 * matters because scope decides which party gets billed, not merely how
 * much. Defaulting it would silently invoice the wrong party.
 */
final class UnrateableTicketException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?RateContext $context = null,
    ) {
        parent::__construct($message);
    }
}
