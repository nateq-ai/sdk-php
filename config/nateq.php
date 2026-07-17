<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | API key
    |--------------------------------------------------------------------------
    |
    | Your Nateq API key, from the dashboard under Settings → API keys. It needs
    | the `emails:send` scope to send and `emails:read` to look sends up.
    |
    | Keep this in .env and out of version control. It is a server-side secret:
    | it carries your organization's full scope grant, so it must never reach a
    | Blade template, a JS bundle, or an API response.
    |
    */
    'api_key' => env('NATEQ_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    |
    | Override only to point at a non-production environment. Anything other
    | than https is refused unless the host is loopback, so the key is never
    | sent in cleartext.
    |
    */
    'base_url' => env('NATEQ_BASE_URL', 'https://api.nateq.io/api/v1'),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | Per-request timeout in seconds. When sending from inside a web request,
    | keep this well under your PHP max_execution_time. Better still, send from
    | a queued job.
    |
    */
    'timeout' => env('NATEQ_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | How many times to retry a retriable failure. Sends are never retried after
    | an ambiguous failure (a timeout or 5xx, where the mail may already have
    | gone out) — only after a 429, which the API raises before anything is sent.
    |
    */
    'max_retries' => env('NATEQ_MAX_RETRIES', 2),
];
