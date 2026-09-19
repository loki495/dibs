<?php

declare(strict_types=1);

namespace App\Services\Activity;

use BackedEnum;
use DateTimeInterface;
use UnitEnum;

/**
 * Makes a value safe to store in an activity log: replaces anything under a secret-like key,
 * truncates long strings, and reduces objects to plain strings so the row can always be
 * JSON-encoded. Both settings are read from config on every call.
 */
final class ActivityRedactor
{
    public const string REDACTED = '[redacted]';

    public function redact(mixed $value): mixed
    {
        return $this->walk($value, $this->patterns(), (int) config('dibs.activity.max_value_bytes'));
    }

    /** @param  list<string>  $patterns */
    private function walk(mixed $value, array $patterns, int $maxBytes): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = is_string($key) && $this->isSecretKey($key, $patterns)
                    ? self::REDACTED
                    : $this->walk($item, $patterns, $maxBytes);
            }

            return $result;
        }

        return match (true) {
            is_string($value) => $this->truncate(mb_convert_encoding($value, 'UTF-8', 'UTF-8'), $maxBytes),
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => is_finite($value) ? $value : (string) $value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof BackedEnum => $this->walk($value->value, $patterns, $maxBytes),
            $value instanceof UnitEnum => $value->name,
            default => get_debug_type($value),
        };
    }

    /** @param  list<string>  $patterns */
    private function isSecretKey(string $key, array $patterns): bool
    {
        $key = mb_strtolower($key);

        foreach ($patterns as $pattern) {
            if (str_contains($key, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function patterns(): array
    {
        $patterns = [];
        foreach ((array) config('dibs.activity.redact_keys') as $pattern) {
            $pattern = mb_strtolower((string) $pattern);
            if ($pattern !== '') {
                $patterns[] = $pattern;
            }
        }

        return $patterns;
    }

    private function truncate(string $value, int $maxBytes): string
    {
        $length = strlen($value);

        if ($maxBytes <= 0 || $length <= $maxBytes) {
            return $value;
        }

        return mb_strcut($value, 0, $maxBytes)."… [truncated, {$length} bytes total]";
    }
}
