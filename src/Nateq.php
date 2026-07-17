<?php

declare(strict_types=1);

namespace Nateq\Sdk;

use JsonSerializable;
use Nateq\Sdk\Exceptions\ConfigurationException;
use Nateq\Sdk\Http\CurlHttpClient;
use Nateq\Sdk\Http\HttpClient;
use Nateq\Sdk\Http\Redactor;
use Nateq\Sdk\Http\Transport;
use Nateq\Sdk\Resources\Emails;

/**
 * The Nateq API client.
 *
 * ```php
 * use Nateq\Sdk\Nateq;
 *
 * $nateq = new Nateq(); // reads NATEQ_API_KEY
 *
 * $nateq->emails()->send(
 *     toEmails: ['customer@example.com'],
 *     subject: 'Welcome aboard',
 *     htmlBody: '<p>Thanks for signing up.</p>',
 * );
 * ```
 *
 * The instance holds your API key, so it is never safe to render: `var_dump`,
 * `json_encode`, and `(string)` all show the key redacted. Serialization is
 * refused outright — see {@see Nateq::__sleep()}.
 *
 * This SDK is server-side only. The key carries your organization's full scope
 * grant; anything shipped to a browser or mobile app is public.
 */
final class Nateq implements JsonSerializable
{
    /**
     * Reported in the User-Agent. Bumped automatically on release — the trailing
     * annotation is what release-please keys on, so don't remove it.
     */
    public const VERSION = '0.1.0'; // x-release-please-version

    public const DEFAULT_BASE_URL = 'https://api.nateq.io/api/v1';

    /** Recognised API-key prefixes, mirroring the API's own validation. */
    private const KEY_PREFIXES = ['tg_live_', 'tg_test_'];

    private const MIN_KEY_LENGTH = 50;

    private readonly string $apiKey;

    public readonly string $baseUrl;

    private readonly Emails $emails;

    /**
     * @param string|null     $apiKey     Defaults to the NATEQ_API_KEY environment variable.
     * @param string|null     $baseUrl    Defaults to NATEQ_BASE_URL, then production.
     * @param float           $timeout    Per-request timeout in seconds.
     * @param int             $maxRetries Retries after a retriable failure. 0 disables.
     * @param HttpClient|null $httpClient Custom transport, for proxies or tests.
     *
     * @throws ConfigurationException if the key or base URL is missing or unusable
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        float $timeout = 30.0,
        int $maxRetries = 2,
        ?HttpClient $httpClient = null,
    ) {
        $this->apiKey = $this->resolveApiKey($apiKey);
        $this->baseUrl = $this->resolveBaseUrl($baseUrl);

        if ($timeout <= 0) {
            throw new ConfigurationException('`timeout` must be greater than zero.');
        }
        if ($maxRetries < 0) {
            throw new ConfigurationException('`maxRetries` cannot be negative.');
        }

        $transport = new Transport(
            apiKey: $this->apiKey,
            baseUrl: $this->baseUrl,
            timeout: $timeout,
            maxRetries: $maxRetries,
            client: $httpClient ?? new CurlHttpClient(),
            userAgent: sprintf('nateq-php/%s (php %s)', self::VERSION, PHP_VERSION),
        );

        $this->emails = new Emails($transport);
    }

    /** Outbound email: send, get, list. */
    public function emails(): Emails
    {
        return $this->emails;
    }

    /** Last four characters of the key, for logging which key is in use. */
    public function apiKeyLast4(): string
    {
        return Redactor::last4($this->apiKey);
    }

    private function resolveApiKey(?string $explicit): string
    {
        $key = $explicit ?? self::env('NATEQ_API_KEY');

        if ($key === null || $key === '') {
            throw new ConfigurationException(
                'No API key provided. Pass `new Nateq(apiKey: ...)` or set the NATEQ_API_KEY environment variable.',
            );
        }
        if ($key !== trim($key)) {
            throw new ConfigurationException(
                'The API key has leading or trailing whitespace, which usually means it was copied incorrectly.',
            );
        }

        // Validate locally so an obviously bad key fails fast, without a
        // round-trip that would put the credential on the wire.
        $hasPrefix = false;
        foreach (self::KEY_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                $hasPrefix = true;
                break;
            }
        }
        if (!$hasPrefix) {
            throw new ConfigurationException(sprintf(
                'Invalid API key format: expected it to start with %s.',
                implode(' or ', self::KEY_PREFIXES),
            ));
        }
        if (strlen($key) < self::MIN_KEY_LENGTH) {
            throw new ConfigurationException('Invalid API key format: the key is too short.');
        }

        return $key;
    }

    private function resolveBaseUrl(?string $explicit): string
    {
        $raw = $explicit ?? self::env('NATEQ_BASE_URL') ?? self::DEFAULT_BASE_URL;

        $parts = parse_url($raw);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new ConfigurationException(sprintf('Invalid baseUrl: %s', $raw));
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        // Refuse to send the key over cleartext. Loopback is exempt so local
        // development against a plain-HTTP server still works.
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        if ($scheme !== 'https' && !$isLoopback) {
            throw new ConfigurationException(sprintf(
                'Refusing to send an API key over %s:// to %s. Use https, or a localhost address for local development.',
                $scheme,
                $host,
            ));
        }

        return rtrim($raw, '/');
    }

    /**
     * Reads an environment variable.
     *
     * Checks the superglobals before getenv() because Laravel's dotenv loader
     * populates $_ENV/$_SERVER, and getenv() misses those on some SAPIs.
     */
    private static function env(string $name): ?string
    {
        foreach ([$_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Keeps the key out of `var_dump()` and framework error pages.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'apiKey' => Redactor::mask($this->apiKey),
        ];
    }

    /**
     * Keeps the key out of `json_encode()` and log context.
     *
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * Refuses serialization.
     *
     * A serialized client would write the key into whatever backs the session,
     * cache, or queue — Redis, a database, a file — in plain text and far
     * outside its intended lifetime. Rebuild the client instead; in Laravel,
     * resolve it from the container.
     */
    public function __sleep(): array
    {
        throw new ConfigurationException(
            'A Nateq client cannot be serialized, because that would write your API key to the session, cache, or queue payload in plain text. Resolve a fresh client instead (in Laravel: app(Nateq::class)).',
        );
    }

    /** @see Nateq::__sleep() — PHP prefers this hook when both are present. */
    public function __serialize(): array
    {
        throw new ConfigurationException(
            'A Nateq client cannot be serialized, because that would write your API key to the session, cache, or queue payload in plain text. Resolve a fresh client instead (in Laravel: app(Nateq::class)).',
        );
    }
}
