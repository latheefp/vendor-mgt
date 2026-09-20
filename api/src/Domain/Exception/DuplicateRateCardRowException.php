<?php
declare(strict_types=1);

namespace App\Domain\Exception;

use InvalidArgumentException;

/**
 * A CSV row matches a line already on the card — by job type, appliance,
 * warranty scope and size band, the fields RateResolver actually matches a
 * ticket against — and is skipped rather than saved as a second line that
 * would only ever shadow the first.
 *
 * A subtype of InvalidArgumentException, not RuntimeException: a duplicate
 * row is a defect in the input, same family as an unknown job type code or
 * a non-numeric amount. RateCardAuthoring::importItems() catches it
 * separately only to flag the resulting error as a duplicate rather than a
 * validation failure, so the upload screen can tell the two apart.
 */
final class DuplicateRateCardRowException extends InvalidArgumentException
{
}
