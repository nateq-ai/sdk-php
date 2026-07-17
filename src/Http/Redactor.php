<?php

declare(strict_types=1);

namespace Nateq\Sdk\Http;

/**
 * Removes the API key from text.
 *
 * Applied to every message the SDK raises, so a leaked stack trace or log line
 * can never reveal the credential. PHP exceptions are routinely rendered by
 * framework error pages and shipped to log aggregators, which makes this the
 * difference between an error report and a credential disclosure.
 */
final class Redactor
{
    public const PLACEHOLDER = '[REDACTED]';

    public static function redact(string $text, string $apiKey): string
    {
        if ($apiKey === '') {
            return $text;
        }

        return str_replace($apiKey, self::PLACEHOLDER, $text);
    }

    /** Last four characters of a key, for logging which key is in use. */
    public static function last4(string $apiKey): string
    {
        return substr($apiKey, -4);
    }

    /** Renders a key as a safe, identifiable placeholder. */
    public static function mask(string $apiKey): string
    {
        return '[REDACTED:...'.self::last4($apiKey).']';
    }
}
