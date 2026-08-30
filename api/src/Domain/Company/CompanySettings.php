<?php
declare(strict_types=1);

namespace App\Domain\Company;

/**
 * The resolved settings for one company.
 *
 * "Resolved" is the whole point. Settings are stored in two layers — a
 * platform default with no owner, and a company's own override — and every
 * consumer wants the answer, not the layering. That merge happens once, in
 * the repository, and what arrives here is a flat map with the winner
 * already chosen.
 *
 * Values are typed on the way in rather than on the way out. A setting
 * stored as `integer` becomes an int here, so a caller asking for the
 * ticket-number width never has to remember that MySQL handed back "6" as
 * a string and that "6" + 1 is not what they wanted.
 *
 * This class knows nothing about CakePHP on purpose, in keeping with the
 * rest of `src/Domain`: the pricing and SLA engines can be handed a
 * settings bag in a unit test without a database.
 */
final readonly class CompanySettings
{
    /**
     * @param array<string, mixed> $values  setting key => already-cast value
     * @param array<string, string> $sources  setting key => 'company'|'platform'
     */
    public function __construct(
        public int $companyId,
        private array $values = [],
        private array $sources = [],
    ) {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * Where a value came from, which is what an admin screen needs in order
     * to show "inherited" next to a field the company has not overridden.
     *
     * @return 'company'|'platform'|'default'
     */
    public function sourceOf(string $key): string
    {
        /** @var 'company'|'platform'|'default' */
        return $this->sources[$key] ?? 'default';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? null;

        return is_scalar($value) ? (string)$value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? null;

        return is_numeric($value) ? (int)$value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * @return array<mixed>
     */
    public function array(string $key, array $default = []): array
    {
        $value = $this->values[$key] ?? null;

        return is_array($value) ? $value : $default;
    }

    /**
     * A decimal kept as a string.
     *
     * Percentages and money-adjacent figures never become floats here for
     * the same reason money is stored in paise: the moment `10.00` is a
     * float somebody adds two of them and gets 20.000000000000004.
     */
    public function decimalString(string $key, string $default = '0.00'): string
    {
        $value = $this->values[$key] ?? null;

        return is_scalar($value) && is_numeric((string)$value) ? (string)$value : $default;
    }

    /**
     * Everything, for an admin screen or a settings API response.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * The same map annotated with where each value came from.
     *
     * @return array<string, array{value: mixed, source: string}>
     */
    public function describe(): array
    {
        $described = [];
        foreach ($this->values as $key => $value) {
            $described[$key] = [
                'value' => $value,
                'source' => $this->sourceOf($key),
            ];
        }

        return $described;
    }
}
