<?php declare(strict_types=1);

namespace GES\Botlock\Config;

/**
 * Typed access to BOTLOCK_* environment variables.
 */
final class Env
{
    public const PREFIX = 'BOTLOCK_';

    /**
     * Returns the trimmed value of BOTLOCK_<NAME>, or $default when unset.
     */
    public static function get(string $name, mixed $default = null): mixed
    {
        $value = \getenv(self::PREFIX . \strtoupper($name));

        if ($value === false) {
            return $default;
        }

        return \is_string($value) ? \trim($value) : $value;
    }

    /**
     * Accepts 1/true/on/yes and 0/false/off/no (case-insensitive).
     * Unset, empty or unrecognized values yield $default.
     */
    public static function bool(string $name, ?bool $default = null): ?bool
    {
        $value = self::get($name);

        if (\is_bool($value)) {
            return $value;
        }

        if (!\is_string($value) || $value === '') {
            return $default;
        }

        return \filter_var($value, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Parses a comma-separated list or a JSON array of strings.
     * An empty value yields an empty array; unset yields $default.
     *
     * @return string[]|null
     * @throws \JsonException
     */
    public static function list(string $name, ?array $default = null): ?array
    {
        $value = self::get($name);

        if (!\is_string($value)) {
            return $default;
        }

        if ($value === '') {
            return [];
        }

        if ($value[0] !== '[' || $value[-1] !== ']') {
            return \explode(',', $value);
        }

        $data = \json_decode($value, true, 512, JSON_THROW_ON_ERROR) ?: [];

        return \array_values(\array_filter($data, 'is_string'));
    }

    public static function int(string $name, int $default): int
    {
        $value = self::get($name);

        return \is_numeric($value) ? (int) $value : $default;
    }
}
