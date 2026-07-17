<?php

declare(strict_types=1);

namespace Nateq\Sdk\Http;

use Nateq\Sdk\Exceptions\AuthenticationException;
use Nateq\Sdk\Exceptions\ConnectionException;
use Nateq\Sdk\Exceptions\NateqException;
use Nateq\Sdk\Exceptions\NotFoundException;
use Nateq\Sdk\Exceptions\PermissionException;
use Nateq\Sdk\Exceptions\RateLimitException;
use Nateq\Sdk\Exceptions\ServerException;
use Nateq\Sdk\Exceptions\TimeoutException;
use Nateq\Sdk\Exceptions\ValidationException;

/**
 * Signs, sends, retries, and error-maps every API call.
 *
 * @internal
 */
final class Transport
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly float $timeout,
        private readonly int $maxRetries,
        private readonly HttpClient $client,
        private readonly string $userAgent,
    ) {
    }

    /**
     * @param array<string, mixed>|null $query
     * @param array<string, mixed>|null $body
     * @param bool $retryOnAmbiguousFailure Whether this call may be replayed after a
     *   failure where the server may already have acted (timeout, 5xx, socket error).
     *   Only ever true for calls with no side effects.
     *
     * @return array<string, mixed>
     */
    public function request(
        string $method,
        string $path,
        ?array $query = null,
        ?array $body = null,
        bool $retryOnAmbiguousFailure = false,
    ): array {
        $url = $this->buildUrl($path, $query);
        $encodedBody = $body !== null ? $this->encodeBody($body) : null;

        $headers = [
            // The key travels in the Authorization header only — never in the URL,
            // where it would land in access logs, Referer headers, and proxies.
            'Authorization' => 'Bearer '.$this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];
        if ($encodedBody !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $lastError = null;

        for ($attempt = 0; $attempt <= $this->maxRetries; ++$attempt) {
            if ($attempt > 0) {
                $retryAfter = $lastError instanceof RateLimitException ? $lastError->retryAfter : null;
                $this->sleep($this->backoffDelay($attempt - 1, $retryAfter));
            }

            try {
                $response = $this->client->send(
                    new Request($method, $url, $headers, $encodedBody),
                    $this->timeout,
                );
            } catch (TimeoutException|ConnectionException $e) {
                // No response: whether the server acted is unknowable.
                $lastError = $this->rewrap($e);

                if ($retryOnAmbiguousFailure && $attempt < $this->maxRetries) {
                    continue;
                }

                throw $lastError;
            }

            if ($response->successful()) {
                return $response->status === 204 ? [] : ($response->json() ?? []);
            }

            $lastError = $this->toException($response);

            $retriable = $lastError instanceof RateLimitException
                || ($retryOnAmbiguousFailure
                    && ($lastError instanceof ServerException || $response->status === 408));

            if ($retriable && $attempt < $this->maxRetries) {
                continue;
            }

            throw $lastError;
        }

        // @codeCoverageIgnoreStart — the loop always returns or throws.
        throw $lastError ?? new NateqException('Request failed.');
        // @codeCoverageIgnoreEnd
    }

    /**
     * Re-creates a transport exception with the key scrubbed from its message.
     * The client is a user-supplied seam, so its text is not trusted to be clean.
     */
    private function rewrap(TimeoutException|ConnectionException $e): NateqException
    {
        $message = Redactor::redact($e->getMessage(), $this->apiKey);

        return $e instanceof TimeoutException
            ? new TimeoutException($message, previous: $e)
            : new ConnectionException($message, previous: $e);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function encodeBody(array $body): string
    {
        // Unescaped slashes and unicode keep subjects and HTML bodies readable on
        // the wire; JSON_THROW_ON_ERROR turns malformed UTF-8 into a typed failure
        // instead of a silent `false` that would send an empty body.
        try {
            return json_encode(
                $body,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException $e) {
            throw new ValidationException(
                'Could not encode the request body as JSON: '.$e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * @param array<string, mixed>|null $query
     */
    private function buildUrl(string $path, ?array $query): string
    {
        $url = rtrim($this->baseUrl, '/').$path;

        $pairs = [];
        foreach ($query ?? [] as $key => $value) {
            if ($value === null) {
                continue;
            }
            // Booleans must go over the wire as true/false, not PHP's 1/"".
            $pairs[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $pairs === [] ? $url : $url.'?'.http_build_query($pairs, '', '&', PHP_QUERY_RFC3986);
    }

    /** Maps a failed response onto the matching exception type. */
    private function toException(Response $response): NateqException
    {
        $payload = $response->json();

        $code = null;
        $message = null;
        $details = null;

        $error = $payload['error'] ?? null;
        if (is_string($error)) {
            // Flat shape: { "error": "Failed to send email", "details": "..." }
            $message = $error;
            $details = $payload['details'] ?? null;
        } elseif (is_array($error)) {
            // Envelope shape: { "success": false, "error": { code, message, details } }
            $code = isset($error['code']) && is_string($error['code']) ? $error['code'] : null;
            $message = isset($error['message']) && is_string($error['message']) ? $error['message'] : null;
            $details = $error['details'] ?? null;
        }

        $status = $response->status;
        $text = Redactor::redact(
            $message ?? sprintf('Request failed with status %d.', $status),
            $this->apiKey,
        );
        $requestId = $response->header('x-request-id');

        return match (true) {
            $status === 401 => new AuthenticationException($text, $status, $code, $details, $requestId),
            $status === 403 => new PermissionException($text, $status, $code, $details, $requestId),
            $status === 404 => new NotFoundException($text, $status, $code, $details, $requestId),
            $status === 400 || $status === 422 => new ValidationException($text, $status, $code, $details, $requestId),
            $status === 429 => new RateLimitException(
                $text,
                $status,
                $code,
                $details,
                $requestId,
                $this->parseRetryAfter($response->header('retry-after')),
            ),
            $status >= 500 => new ServerException($text, $status, $code, $details, $requestId),
            default => new NateqException($text, $status, $code, $details, $requestId),
        };
    }

    private function parseRetryAfter(?string $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            $seconds = (float) $raw;

            return $seconds >= 0 ? $seconds : null;
        }

        $date = strtotime($raw);
        if ($date === false) {
            return null;
        }

        return max(0.0, (float) ($date - time()));
    }

    /** Full-jitter exponential backoff, capped at 8s, honouring Retry-After. */
    private function backoffDelay(int $attempt, ?float $retryAfter): float
    {
        if ($retryAfter !== null) {
            return min($retryAfter, 60.0);
        }

        $ceiling = min(0.5 * (2 ** $attempt), 8.0);

        // Full jitter: spreads a thundering herd of retrying workers instead of
        // having them all wake at the same instant and re-collide.
        return (mt_rand() / mt_getrandmax()) * $ceiling;
    }

    private function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }
}
