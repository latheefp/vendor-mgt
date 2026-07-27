<?php
declare(strict_types=1);

namespace App\Domain\Company;

/**
 * What one setting is, independent of what any company has set it to.
 *
 * The catalogue exists so the settings API can be a validating endpoint
 * rather than a free-form key/value dump. Without it, `PUT /settings` with
 * a typo in the key succeeds, writes a row nothing ever reads, and the
 * admin who typed it believes the setting is applied — a failure mode with
 * no error message anywhere.
 */
final readonly class SettingDefinition
{
    public function __construct(
        public string $key,
        public string $type,
        public string $label,
        public mixed $default = null,
        public string $description = '',
        /** Grouping for the admin screen. */
        public string $group = 'general',
        /** Ours to set, not the company's. */
        public bool $isEditable = true,
        /** Permitted values, for settings that are a closed choice. */
        public ?array $allowed = null,
    ) {
    }

    /**
     * Cast a stored string to this setting's declared type.
     *
     * Everything is stored in one TEXT column, so this is the only place a
     * value becomes what it claims to be.
     */
    public function cast(?string $raw): mixed
    {
        if ($raw === null) {
            return $this->default;
        }

        return match ($this->type) {
            'integer' => (int)$raw,
            // Booleans arrive as "1"/"0" from MySQL and as "true"/"false"
            // from a hand-written API call; both have to work.
            'boolean' => in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true),
            'json' => $this->castJson($raw),
            // 'decimal' deliberately stays a string — see CompanySettings.
            default => $raw,
        };
    }

    private function castJson(string $raw): mixed
    {
        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $this->default;
    }

    /**
     * Serialise an incoming value for storage, or explain why it cannot be.
     *
     * @return array{ok: true, value: string}|array{ok: false, error: string}
     */
    public function serialize(mixed $value): array
    {
        if ($this->allowed !== null && !in_array($value, $this->allowed, true)) {
            return [
                'ok' => false,
                'error' => sprintf('Must be one of: %s.', implode(', ', array_map('strval', $this->allowed))),
            ];
        }

        return match ($this->type) {
            'integer' => is_numeric($value) && (string)(int)$value === (string)$value
                ? ['ok' => true, 'value' => (string)(int)$value]
                : ['ok' => false, 'error' => 'Must be a whole number.'],

            'decimal' => is_numeric($value)
                ? ['ok' => true, 'value' => number_format((float)$value, 2, '.', '')]
                : ['ok' => false, 'error' => 'Must be a number.'],

            'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true)
                ? ['ok' => true, 'value' => $this->truthy($value) ? '1' : '0']
                : ['ok' => false, 'error' => 'Must be true or false.'],

            'json' => is_array($value)
                ? ['ok' => true, 'value' => (string)json_encode($value)]
                : ['ok' => false, 'error' => 'Must be a list or an object.'],

            default => is_scalar($value)
                ? ['ok' => true, 'value' => (string)$value]
                : ['ok' => false, 'error' => 'Must be text.'],
        };
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type,
            'label' => $this->label,
            'description' => $this->description,
            'group' => $this->group,
            'default' => $this->default,
            'is_editable' => $this->isEditable,
            'allowed' => $this->allowed,
        ];
    }
}
