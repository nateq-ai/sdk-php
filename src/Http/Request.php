<?php

declare(strict_types=1);

namespace Nateq\Sdk\Http;

/** An outbound HTTP request, as handed to an {@see HttpClient}. */
final class Request
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
    ) {
    }
}
