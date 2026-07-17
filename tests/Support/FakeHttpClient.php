<?php

declare(strict_types=1);

namespace Nateq\Sdk\Tests\Support;

use Nateq\Sdk\Exceptions\NateqException;
use Nateq\Sdk\Http\HttpClient;
use Nateq\Sdk\Http\Request;
use Nateq\Sdk\Http\Response;

/**
 * An HttpClient that returns queued responses and records what it was asked to
 * send, so tests can assert on the exact wire format without a network.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<Response|NateqException> */
    private array $queue = [];

    /**
     * @param list<Response|NateqException> $queue Responses in order; an exception
     *   is thrown instead of returned, simulating a transport failure.
     */
    public function __construct(array $queue = [])
    {
        $this->queue = $queue;
    }

    public static function json(int $status, array $body = [], array $headers = []): Response
    {
        return new Response($status, $headers + ['content-type' => 'application/json'], json_encode($body));
    }

    public function send(Request $request, float $timeoutSeconds): Response
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException(sprintf(
                'FakeHttpClient ran out of queued responses on call %d to %s.',
                count($this->requests),
                $request->url,
            ));
        }

        if ($next instanceof NateqException) {
            throw $next;
        }

        return $next;
    }

    public function callCount(): int
    {
        return count($this->requests);
    }

    public function lastRequest(): Request
    {
        return $this->requests[count($this->requests) - 1];
    }

    public function requestAt(int $index): Request
    {
        return $this->requests[$index];
    }

    /** @return array<string, mixed> */
    public function lastBody(): array
    {
        return json_decode($this->lastRequest()->body ?? '{}', true);
    }
}
