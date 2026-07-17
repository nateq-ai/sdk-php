<?php

declare(strict_types=1);

namespace Nateq\Sdk\Http;

use Nateq\Sdk\Exceptions\ConnectionException;
use Nateq\Sdk\Exceptions\TimeoutException;

/**
 * The transport seam.
 *
 * Implement this to route SDK traffic through your own stack (Guzzle, Symfony
 * HttpClient, an outbound proxy) or to fake the API in tests. The SDK ships
 * {@see CurlHttpClient} and uses it unless you pass your own.
 */
interface HttpClient
{
    /**
     * Performs the request and returns the response, whatever its status.
     *
     * Only transport failures throw — a 4xx/5xx must be returned as a Response
     * so the SDK can map it onto the right exception type.
     *
     * @throws TimeoutException    when the request exceeds $timeoutSeconds
     * @throws ConnectionException when no response could be obtained
     */
    public function send(Request $request, float $timeoutSeconds): Response;
}
