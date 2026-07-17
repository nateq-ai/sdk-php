<?php

declare(strict_types=1);

namespace Nateq\Sdk\Exceptions;

use Throwable;

/**
 * A rate limit was hit (429).
 *
 * The API enforces limits during validation, before any mail leaves, so a 429
 * means nothing was sent — this is the one failure the SDK retries even for
 * non-idempotent calls like send().
 */
class RateLimitException extends NateqException
{
    /** Seconds to wait before retrying, when the API supplies `Retry-After`. */
    public readonly ?float $retryAfter;

    public function __construct(
        string $message,
        ?int $status = null,
        ?string $errorCode = null,
        mixed $details = null,
        ?string $requestId = null,
        ?float $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $errorCode, $details, $requestId, $previous);

        $this->retryAfter = $retryAfter;
    }
}
