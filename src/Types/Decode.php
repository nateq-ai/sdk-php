<?php

declare(strict_types=1);

namespace Nateq\Sdk\Types;

/**
 * Defensive decoding helpers.
 *
 * The API is a moving target and PHP will happily coerce a null into "" or an
 * array into a fatal. These keep a surprising payload from becoming a TypeError
 * in the caller's request handler.
 *
 * @internal
 */
final class Decode
{
    /** Maps a status onto the enum, falling back to the raw string. */
    public static function status(mixed $value): EmailStatus|string
    {
        if (!is_string($value)) {
            return '';
        }

        return EmailStatus::tryFrom($value) ?? $value;
    }

    public static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    public static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    public static function bool(mixed $value, bool $default = false): bool
    {
        return is_bool($value) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
