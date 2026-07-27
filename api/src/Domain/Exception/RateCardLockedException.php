<?php
declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

/**
 * Someone tried to edit a rate card that is no longer a draft.
 *
 * Thrown rather than silently ignored, because the alternative is worse
 * than an error: frozen charge lines hold a hard reference to the rate
 * card item that priced them, so amending a published item rewrites the
 * explanation of money already invoiced and, in some cases, already paid.
 * The invoice total stays as it was; only the reasoning behind it changes,
 * which is the hardest kind of discrepancy to find months later.
 *
 * The correct move is always the same — clone the card to a new version,
 * edit that, publish it — so the message says so.
 */
final class RateCardLockedException extends RuntimeException
{
}
