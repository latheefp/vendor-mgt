<?php
declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An exact rupee amount, held as integer paise.
 *
 * Money never touches a float in this application. MySQL returns DECIMAL
 * as a string, and the first time anyone writes `$a + $b` against two of
 * those they are silently doing float arithmetic on someone's wages.
 * Integer minor units make that impossible.
 *
 * Percentages are applied through integer basis points for the same
 * reason: 12.5% becomes 1250bp, and the multiply/divide stays in int64
 * the whole way. Rounding is half-up away from zero, which is what a
 * person doing this on paper would do.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(public int $paise)
    {
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromPaise(int $paise): self
    {
        return new self($paise);
    }

    /**
     * Whole rupees, e.g. the Rs.350 installation rate.
     */
    public static function fromRupees(int $rupees): self
    {
        return new self($rupees * 100);
    }

    /**
     * Parse a decimal rupee string such as "1234.50".
     *
     * Accepts at most two decimal places; three would mean the caller has
     * a precision expectation this type cannot honour, so we reject it
     * rather than round silently.
     */
    public static function parse(string $amount): self
    {
        $trimmed = trim($amount);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $trimmed, $m)) {
            throw new InvalidArgumentException(
                sprintf('Cannot parse "%s" as a rupee amount.', $amount),
            );
        }

        $paise = ((int)$m[2]) * 100 + (int)str_pad($m[3] ?? '0', 2, '0');

        return new self($m[1] === '-' ? -$paise : $paise);
    }

    public function plus(self $other): self
    {
        return new self($this->paise + $other->paise);
    }

    public function minus(self $other): self
    {
        return new self($this->paise - $other->paise);
    }

    public function times(int $factor): self
    {
        return new self($this->paise * $factor);
    }

    public function negate(): self
    {
        return new self(-$this->paise);
    }

    public function absolute(): self
    {
        return new self(abs($this->paise));
    }

    /**
     * A percentage of this amount, given as a decimal string like "10" or
     * "12.50" — the form vendor agreements are actually written in.
     */
    public function percentage(string $percent): self
    {
        return $this->basisPoints(self::percentToBasisPoints($percent));
    }

    /**
     * A percentage expressed in basis points (100bp = 1%).
     */
    public function basisPoints(int $basisPoints): self
    {
        return new self(self::divideHalfUp($this->paise * $basisPoints, 10_000));
    }

    /**
     * Multiply by a decimal quantity such as 7.40 kilometres.
     *
     * The quantity is converted to hundredths first so the arithmetic
     * stays integral: Rs.3/km over 7.4km is 300 * 740 / 100 = 2220 paise.
     */
    public function timesQuantity(string $quantity): self
    {
        $hundredths = self::decimalToHundredths($quantity);

        return new self(self::divideHalfUp($this->paise * $hundredths, 100));
    }

    public function isZero(): bool
    {
        return $this->paise === 0;
    }

    public function isNegative(): bool
    {
        return $this->paise < 0;
    }

    public function isPositive(): bool
    {
        return $this->paise > 0;
    }

    public function equals(self $other): bool
    {
        return $this->paise === $other->paise;
    }

    public function greaterThan(self $other): bool
    {
        return $this->paise > $other->paise;
    }

    public function lessThan(self $other): bool
    {
        return $this->paise < $other->paise;
    }

    /**
     * @param iterable<self> $amounts
     */
    public static function sum(iterable $amounts): self
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount->paise;
        }

        return new self($total);
    }

    /**
     * Decimal rupees, for display and for JSON responses.
     */
    public function toRupeeString(): string
    {
        $sign = $this->paise < 0 ? '-' : '';
        $abs = abs($this->paise);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /**
     * Indian-format currency, e.g. "₹1,23,456.50". Grouping is 3 then 2s,
     * which is why this cannot use number_format().
     */
    public function format(bool $withSymbol = true): string
    {
        $sign = $this->paise < 0 ? '-' : '';
        $abs = abs($this->paise);
        $rupees = (string)intdiv($abs, 100);
        $fraction = sprintf('%02d', $abs % 100);

        if (strlen($rupees) > 3) {
            $last3 = substr($rupees, -3);
            $rest = substr($rupees, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) ?? $rest;
            $rupees = $rest . ',' . $last3;
        }

        return $sign . ($withSymbol ? '₹' : '') . $rupees . '.' . $fraction;
    }

    public function __toString(): string
    {
        return $this->toRupeeString();
    }

    /**
     * Both representations go over the wire: `paise` is what the client
     * should compute with, `formatted` is what it should print.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'paise' => $this->paise,
            'rupees' => $this->toRupeeString(),
            'formatted' => $this->format(),
        ];
    }

    /**
     * "12.5" -> 1250. Rejects anything finer than a basis point.
     */
    public static function percentToBasisPoints(string $percent): int
    {
        $trimmed = trim($percent);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $trimmed, $m)) {
            throw new InvalidArgumentException(
                sprintf('Cannot parse "%s" as a percentage.', $percent),
            );
        }

        $bp = ((int)$m[2]) * 100 + (int)str_pad($m[3] ?? '0', 2, '0');

        return $m[1] === '-' ? -$bp : $bp;
    }

    /**
     * "7.4" -> 740.
     */
    private static function decimalToHundredths(string $value): int
    {
        $trimmed = trim($value);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $trimmed, $m)) {
            throw new InvalidArgumentException(
                sprintf('Cannot parse "%s" as a decimal quantity.', $value),
            );
        }

        $hundredths = ((int)$m[2]) * 100 + (int)str_pad($m[3] ?? '0', 2, '0');

        return $m[1] === '-' ? -$hundredths : $hundredths;
    }

    /**
     * Integer division rounding halves away from zero, so that -2.5 goes to
     * -3 just as 2.5 goes to 3. Symmetry matters here: a penalty and the
     * bonus it offsets must round by the same magnitude.
     */
    private static function divideHalfUp(int $numerator, int $denominator): int
    {
        $negative = ($numerator < 0) !== ($denominator < 0);
        $absNumerator = abs($numerator);
        $absDenominator = abs($denominator);

        $quotient = intdiv($absNumerator, $absDenominator);
        $remainder = $absNumerator % $absDenominator;

        if ($remainder * 2 >= $absDenominator) {
            $quotient++;
        }

        return $negative ? -$quotient : $quotient;
    }
}
