<?php
declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

/**
 * A ticket was asked to move to a status it cannot reach from where it is.
 *
 * The transitions worth blocking are the ones nobody sets out to do:
 * closing a job that was never visited, or quietly editing a ticket whose
 * charges are already on an invoice. Both look like ordinary saves in a
 * controller and are only visible later, as a closure with no visit
 * timestamp or an invoice that no longer matches its tickets.
 *
 * The message names the legal moves, because the caller is usually a UI
 * that has offered the wrong button and the fastest fix is knowing which
 * buttons were right.
 */
final class TicketTransitionException extends RuntimeException
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly array $allowed = [],
    ) {
        parent::__construct(sprintf(
            'A ticket that is "%s" cannot become "%s". %s',
            $fromStatus,
            $toStatus,
            $allowed === []
                ? 'It is in a terminal state.'
                : sprintf('It can become: %s.', implode(', ', $allowed)),
        ));
    }
}
