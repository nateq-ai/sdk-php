# Nateq PHP SDK

[![Packagist Version](https://img.shields.io/packagist/v/nateq/sdk.svg?style=flat-square)](https://packagist.org/packages/nateq/sdk)
[![PHP Version](https://img.shields.io/packagist/dependency-v/nateq/sdk/php.svg?style=flat-square)](https://packagist.org/packages/nateq/sdk)
[![CI](https://img.shields.io/github/actions/workflow/status/nateq-ai/sdk-php/ci.yml?branch=main&style=flat-square&label=ci)](https://github.com/nateq-ai/sdk-php/actions)
[![License](https://img.shields.io/packagist/l/nateq/sdk.svg?style=flat-square)](LICENSE)

The official PHP client for the [Nateq](https://nateq.io) API, with first-class Laravel support.
Send transactional email from your application and look up what happened to it.

Zero required dependencies, typed results, and an HTTP layer you can swap out in tests.

> **This is a server-side SDK.** Your API key carries your organization's full scope grant.
> Never ship it to a browser, a mobile app, a Blade-rendered `<script>` block, or any
> response body. Keep it in `.env`, out of version control, and out of your logs.

## Contents

- [Requirements](#requirements)
- [Install](#install)
- [Quick start](#quick-start)
- [Authentication](#authentication)
- [Sending email](#sending-email)
- [Reading email](#reading-email)
- [Errors](#errors)
- [Retries and duplicate sends](#retries-and-duplicate-sends)
- [Laravel](#laravel)
- [Other frameworks](#other-frameworks)
- [Testing](#testing)
- [Contributing](#contributing)
- [License](#license)

## Requirements

- PHP 8.2 or newer
- `ext-curl` and `ext-json`
- Laravel 11 or 12 (optional — the SDK works without it)

PHP 8.1 and Laravel 10 are not supported: both left security support before this
package existed. The test suite runs on PHP 8.2, 8.3, and 8.4 against Laravel 12.

## Install

```bash
composer require nateq/sdk
```

In Laravel the service provider and `Nateq` facade are auto-discovered. Nothing to register.

## Quick start

```php
use Nateq\Sdk\Nateq;

$nateq = new Nateq(); // reads NATEQ_API_KEY

$result = $nateq->emails()->send(
    toEmails: ['customer@example.com'],
    subject: 'Welcome aboard',
    htmlBody: '<p>Thanks for signing up.</p>',
);

echo $result->id; // "9f8c…" — use this to look the email up later
```

In Laravel, resolve the client instead of constructing it:

```php
use Nateq\Sdk\Nateq;

class WelcomeMailer
{
    public function __construct(private Nateq $nateq) {}

    public function send(User $user): void
    {
        $this->nateq->emails()->send(
            toEmails: [$user->email],
            subject: 'Welcome aboard',
            htmlBody: view('mail.welcome', ['user' => $user])->render(),
        );
    }
}
```

## Authentication

Create an API key in the dashboard under **Settings → API keys**. It needs:

| Scope | Grants |
| --- | --- |
| `emails:send` | Sending mail |
| `emails:read` | Looking sends up (`get`, `list`) |

Put it in your environment:

```bash
NATEQ_API_KEY=tg_live_…
```

The SDK reads `NATEQ_API_KEY` by default. To pass it explicitly — from a secret manager,
or when you talk to more than one organization:

```php
$nateq = new Nateq(apiKey: $secrets->get('nateq_api_key'));
```

The key is validated locally before any request, so a mistyped key fails immediately
instead of being sent over the network. It travels in the `Authorization` header only,
never in a URL where it would land in access logs and `Referer` headers.

**The client will not leak the key when rendered.** `var_dump`, `json_encode`, and
framework error pages all show it redacted, and serializing a client throws rather than
writing your key into a session, cache entry, or queue payload:

```php
var_dump($nateq);
// object(Nateq\Sdk\Nateq) { ["baseUrl"]=> "https://api.nateq.io/api/v1"
//                           ["apiKey"]=> "[REDACTED:…ghij]" }
```

> `print_r()` and `var_export()` have no redaction hook in PHP and **will** print private
> properties verbatim. Don't pass the client to either.

## Sending email

`send()` returns once the API has accepted the message. Delivery happens afterwards —
a successful return means Nateq took responsibility for it, not that it landed.

```php
$result = $nateq->emails()->send(
    toEmails: ['customer@example.com'],
    subject: 'Your receipt',
    htmlBody: '<p>Thanks!</p>',
    plainTextBody: 'Thanks!',
);

$result->id;                // string
$result->status;            // EmailStatus::Pending
$result->providerMessageId; // ?string
```

Every option, all optional except `toEmails` and `subject`:

```php
$nateq->emails()->send(
    toEmails: ['a@example.com', 'b@example.com'],
    subject: 'Quarterly update',
    htmlBody: '<p>Hello</p>',
    plainTextBody: 'Hello',
    fromEmail: 'hello@yourdomain.com',   // must be a verified org address
    fromName: 'Acme Support',
    emailAddressId: '…',                 // or pick the verified address by id
    ccEmails: ['c@example.com'],
    bccEmails: ['d@example.com'],
    replyTo: 'support@yourdomain.com',
    inReplyTo: '<message-id@…>',         // threading
    references: '<message-id@…>',
    ticketId: '…',                       // link the send to a ticket…
    conversationId: '…',                 // …a conversation…
    contactId: '…',                      // …or a contact
    attachmentIds: ['…'],                // ids of previously uploaded files
    headers: ['X-Campaign' => 'q3'],
);
```

Provide `htmlBody`, `plainTextBody`, or both. Omit `fromEmail` and `emailAddressId` and
your organization's default verified address is used.

## Reading email

Look up a single send, including its delivery status:

```php
$email = $nateq->emails()->get($result->id);

$email->status;       // EmailStatus::Delivered
$email->openCount;    // int
$email->bounceReason; // ?string
```

`EmailStatus` is an enum with helpers, so you don't have to memorise which of the nine
statuses count as success:

```php
if ($email->status->isFailure()) {   // bounced | failed | rejected
    Log::warning('Email did not arrive', ['reason' => $email->bounceReason]);
}

$email->status->isDelivered(); // delivered | opened | clicked
$email->status->isPending();   // pending | sending | sent
```

List sends, newest first. The result is countable and iterable:

```php
use Nateq\Sdk\Types\EmailStatus;

$page = $nateq->emails()->list(
    status: EmailStatus::Bounced,
    toEmail: 'customer@example.com',
    limit: 25,
);

foreach ($page as $email) {
    echo $email->subject;
}

$page->total;        // total across all pages
$page->hasMore();    // bool
$page->nextOffset(); // ?int — feed straight back into list(offset: …)
```

Paging through everything:

```php
$offset = 0;
do {
    $page = $nateq->emails()->list(limit: 100, offset: $offset);
    foreach ($page as $email) {
        // …
    }
    $offset = $page->nextOffset();
} while ($offset !== null);
```

`limit` defaults to 50 and is capped at 100 server-side.

The API returns more fields than the SDK models. Anything not promoted to a property is
still available on `$email->raw`, so a newly added field works without an SDK upgrade.

## Errors

Everything the SDK throws extends `NateqException`, so one `catch` covers the surface:

```php
use Nateq\Sdk\Exceptions\NateqException;
use Nateq\Sdk\Exceptions\PermissionException;
use Nateq\Sdk\Exceptions\RateLimitException;
use Nateq\Sdk\Exceptions\ValidationException;

try {
    $nateq->emails()->send(/* … */);
} catch (ValidationException $e) {
    // 400/422 — the request was wrong. Don't retry it unchanged.
} catch (PermissionException $e) {
    // 403 — key lacks emails:send, endpoint isn't public to keys, or IP not allowed.
    $e->errorCode; // e.g. "INSUFFICIENT_SCOPE"
} catch (RateLimitException $e) {
    // 429 — nothing was sent.
    $e->retryAfter; // ?float, seconds
} catch (NateqException $e) {
    $e->status;    // ?int  HTTP status
    $e->errorCode; // ?string machine-readable code
    $e->details;   // mixed  validation details, scope lists, …
    $e->requestId; // ?string — quote this to support
}
```

| Exception | When |
| --- | --- |
| `ValidationException` | 400/422, or bad arguments caught before any request |
| `AuthenticationException` | 401 — key missing, malformed, revoked, unknown |
| `PermissionException` | 403 — key valid, but not allowed to do this |
| `NotFoundException` | 404 |
| `RateLimitException` | 429 — carries `retryAfter` |
| `ServerException` | 5xx |
| `TimeoutException` | exceeded the configured timeout |
| `ConnectionException` | DNS, TLS, socket, offline |
| `ConfigurationException` | bad SDK setup — thrown before any request |

No exception ever carries your API key: every message is scrubbed before it is raised,
because framework error pages and log aggregators render these by default.

## Retries and duplicate sends

Reads (`get`, `list`) are retried automatically on timeouts, 5xx, and connection
failures, with full-jitter exponential backoff that honours `Retry-After`.

**`send()` is deliberately not.** The API has no idempotency key, so a send that fails
*after* reaching the server may already have gone out. Replaying it could mail your
customer twice, and the SDK will not make that decision for you.

The one exception is a **429**, which the API raises during validation before anything is
sent — so it is retried, because a duplicate is impossible.

If a `send()` throws `TimeoutException` or `ServerException`, the outcome is genuinely
unknown. Look it up before retrying:

```php
use Nateq\Sdk\Exceptions\ServerException;
use Nateq\Sdk\Exceptions\TimeoutException;

try {
    $nateq->emails()->send(toEmails: [$user->email], subject: $subject, htmlBody: $html);
} catch (TimeoutException|ServerException $e) {
    // Might have sent. Check before trying again.
    $recent = $nateq->emails()->list(toEmail: $user->email, limit: 5);
    // …decide based on what's there
}
```

Tune retries with `maxRetries` (`0` disables them):

```php
$nateq = new Nateq(timeout: 10.0, maxRetries: 0);
```

## Laravel

The package auto-discovers. Publish the config only if you want to edit it:

```bash
php artisan vendor:publish --tag=nateq-config
```

```php
// config/nateq.php
return [
    'api_key' => env('NATEQ_API_KEY'),
    'base_url' => env('NATEQ_BASE_URL', 'https://api.nateq.io/api/v1'),
    'timeout' => env('NATEQ_TIMEOUT', 30),
    'max_retries' => env('NATEQ_MAX_RETRIES', 2),
];
```

Inject the client, or use the facade:

```php
use Nateq\Sdk\Laravel\Facades\Nateq;

Nateq::emails()->send(
    toEmails: ['customer@example.com'],
    subject: 'Welcome aboard',
    htmlBody: '<p>Thanks for signing up.</p>',
);
```

**Send from a queued job, not from the request.** Email is a network call to a third
party; doing it inline ties your response time to ours and turns our bad minute into your
timeout.

```php
class SendWelcomeEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(public User $user) {}

    // Do not retry blindly: the send may have succeeded before the failure.
    public $tries = 1;

    public function handle(Nateq $nateq): void
    {
        $nateq->emails()->send(
            toEmails: [$this->user->email],
            subject: 'Welcome aboard',
            htmlBody: view('mail.welcome', ['user' => $this->user])->render(),
        );
    }
}
```

Resolve the client via the `handle()` signature as above — never store it on the job as a
property. Job properties are serialized into the queue payload, and the client refuses to
serialize precisely so your API key can't end up sitting in Redis in plain text.

If you change `NATEQ_API_KEY` and nothing seems to happen, you have a cached config:

```bash
php artisan config:clear
```

## Other frameworks

The core has no framework ties — construct `Nateq` and go. In Symfony, register it as a
service:

```yaml
# config/services.yaml
services:
    Nateq\Sdk\Nateq:
        arguments:
            $apiKey: '%env(NATEQ_API_KEY)%'
```

To route traffic through your own HTTP stack (an outbound proxy, Guzzle, Symfony
HttpClient), implement `HttpClient`:

```php
use Nateq\Sdk\Http\{HttpClient, Request, Response};

final class GuzzleHttpClient implements HttpClient
{
    public function __construct(private \GuzzleHttp\Client $guzzle) {}

    public function send(Request $request, float $timeoutSeconds): Response
    {
        $res = $this->guzzle->request($request->method, $request->url, [
            'headers' => $request->headers,
            'body' => $request->body,
            'timeout' => $timeoutSeconds,
            'http_errors' => false, // the SDK maps statuses itself
        ]);

        return new Response(
            $res->getStatusCode(),
            array_map(fn ($v) => $v[0], $res->getHeaders()),
            (string) $res->getBody(),
        );
    }
}

$nateq = new Nateq(httpClient: new GuzzleHttpClient($guzzle));
```

## Testing

Don't hit the API from your test suite. Implement `HttpClient` with a fake and inject it:

```php
use Nateq\Sdk\Http\{HttpClient, Request, Response};
use Nateq\Sdk\Nateq;

$fake = new class implements HttpClient {
    public array $requests = [];

    public function send(Request $request, float $timeoutSeconds): Response
    {
        $this->requests[] = $request;

        return new Response(201, [], json_encode([
            'id' => 'test-id', 'status' => 'pending', 'createdAt' => 'now',
        ]));
    }
};

$nateq = new Nateq(apiKey: 'tg_test_…', httpClient: $fake);
```

In Laravel, bind it and the container-resolved client picks it up — facade included:

```php
$this->app->instance(HttpClient::class, $fake);

Nateq::emails()->send(toEmails: ['a@example.com'], subject: 'Hi', htmlBody: '<p>Hi</p>');

$this->assertCount(1, $fake->requests);
```

## Contributing

```bash
composer install
composer test    # phpunit
composer stan    # phpstan, level 6
composer fmt     # php-cs-fixer
composer check   # all three
```

## License

[MIT](LICENSE)
