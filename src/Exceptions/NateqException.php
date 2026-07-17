<?php

declare(strict_types=1);

namespace Nateq\Sdk\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class for every error raised by the SDK, so a single
 * `catch (NateqException $e)` covers the whole surface.
 *
 * No exception ever carries the API key: every message passes through
 * Redactor::redact() before it reaches a constructor.
 */
class NateqException extends RuntimeException
{
    /** HTTP status, when the failure came from a response. */
    public readonly ?int $status;

    /** API error code, when the response carried one (e.g. INSUFFICIENT_SCOPE). */
    public readonly ?string $errorCode;

    /** Extra context from the API (validation details, scope lists, ...). */
    public readonly mixed $details;

    /** Value of the X-Request-Id response header, useful for support tickets. */
    public readonly ?string $requestId;

    public function __construct(
        string $message,
        ?int $status = null,
        ?string $errorCode = null,
        mixed $details = null,
        ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);

        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->details = $details;
        $this->requestId = $requestId;
    }
}
