<?php

declare(strict_types=1);

namespace Nateq\Sdk\Http;

use Nateq\Sdk\Exceptions\ConnectionException;
use Nateq\Sdk\Exceptions\TimeoutException;

/**
 * The default {@see HttpClient}, built on ext-curl.
 *
 * Deliberately dependency-free: an SDK that pinned a Guzzle major would force
 * that choice on every host application, and Laravel apps that already ship a
 * different version would fail to install.
 */
final class CurlHttpClient implements HttpClient
{
    public function send(Request $request, float $timeoutSeconds): Response
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new ConnectionException('Could not initialise a cURL handle.');
        }

        $responseHeaders = [];

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->formatHeaders($request->headers),

            // Never follow redirects. cURL would replay the request at the new
            // location with the Authorization header still attached, handing the
            // API key to whatever host the redirect names.
            CURLOPT_FOLLOWLOCATION => false,

            // Certificate and hostname verification, stated rather than assumed:
            // these are the defaults, but a php.ini or a well-meaning "fix" can
            // turn them off globally, and this is the credential's only shield.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            // Confine cURL to HTTP(S); without this a crafted URL could reach
            // file:// or gopher:// handlers.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,

            // Milliseconds, so sub-second timeouts are expressible.
            CURLOPT_TIMEOUT_MS => (int) round($timeoutSeconds * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round(min($timeoutSeconds, 10.0) * 1000),

            CURLOPT_HEADERFUNCTION => function ($_handle, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ];

        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }

        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($errno !== 0 || $body === false) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new TimeoutException(
                    sprintf('Request timed out after %.1fs.', $timeoutSeconds),
                );
            }

            throw new ConnectionException(
                sprintf('Could not reach the Nateq API: %s', $error !== '' ? $error : 'unknown cURL error'),
            );
        }

        return new Response($status, $responseHeaders, (string) $body);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name.': '.$value;
        }

        return $formatted;
    }
}
