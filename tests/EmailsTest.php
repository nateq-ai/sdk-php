<?php

declare(strict_types=1);

namespace Nateq\Sdk\Tests;

use Nateq\Sdk\Exceptions\AuthenticationException;
use Nateq\Sdk\Exceptions\ConnectionException;
use Nateq\Sdk\Exceptions\NotFoundException;
use Nateq\Sdk\Exceptions\PermissionException;
use Nateq\Sdk\Exceptions\RateLimitException;
use Nateq\Sdk\Exceptions\ServerException;
use Nateq\Sdk\Exceptions\ValidationException;
use Nateq\Sdk\Nateq;
use Nateq\Sdk\Tests\Support\FakeHttpClient;
use Nateq\Sdk\Types\EmailStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailsTest extends TestCase
{
    private const KEY = 'tg_live_abcdefghijklmnopqrstuvwxyz0123456789abcdefghij';

    protected function setUp(): void
    {
        unset($_ENV['NATEQ_API_KEY'], $_ENV['NATEQ_BASE_URL'], $_SERVER['NATEQ_API_KEY'], $_SERVER['NATEQ_BASE_URL']);
        putenv('NATEQ_API_KEY');
        putenv('NATEQ_BASE_URL');
    }

    /**
     * @param list<mixed> $queue
     */
    private function client(array $queue, int $maxRetries = 0, ?string $baseUrl = null): array
    {
        $http = new FakeHttpClient($queue);
        $client = new Nateq(
            apiKey: self::KEY,
            baseUrl: $baseUrl,
            timeout: 5.0,
            maxRetries: $maxRetries,
            httpClient: $http,
        );

        return [$client, $http];
    }

    private static function accepted(): object
    {
        return FakeHttpClient::json(201, [
            'id' => 'e1',
            'status' => 'pending',
            'createdAt' => '2026-07-17T00:00:00Z',
            'providerMessageId' => 'pm-1',
        ]);
    }

    // ---------------------------------------------------------------- send

    public function test_send_posts_to_the_right_url_on_the_default_base_url(): void
    {
        // Deliberately does NOT override baseUrl. The Node SDK once shipped a
        // double-"/v1" because every test supplied its own base URL and nothing
        // ever exercised the default.
        [$client, $http] = $this->client([self::accepted()]);

        $client->emails()->send(toEmails: ['a@example.com'], subject: 'Hi', htmlBody: '<p>Hi</p>');

        $this->assertSame(
            'https://api.nateq.io/api/v1/outbound-emails',
            $http->lastRequest()->url,
        );
    }

    public function test_send_uses_post_and_bearer_auth(): void
    {
        [$client, $http] = $this->client([self::accepted()]);

        $client->emails()->send(toEmails: ['a@example.com'], subject: 'Hi', htmlBody: '<p>Hi</p>');

        $request = $http->lastRequest();
        $this->assertSame('POST', $request->method);
        $this->assertSame('Bearer '.self::KEY, $request->headers['Authorization']);
        $this->assertSame('application/json', $request->headers['Content-Type']);
        $this->assertStringStartsWith('nateq-php/', $request->headers['User-Agent']);
    }

    public function test_the_key_never_appears_in_the_url(): void
    {
        [$client, $http] = $this->client([self::accepted()]);

        $client->emails()->send(toEmails: ['a@example.com'], subject: 'Hi', htmlBody: '<p>Hi</p>');

        $this->assertStringNotContainsString(self::KEY, $http->lastRequest()->url);
    }

    public function test_send_serialises_the_documented_wire_format(): void
    {
        [$client, $http] = $this->client([self::accepted()]);

        $client->emails()->send(
            toEmails: ['a@example.com', 'b@example.com'],
            subject: 'Quarterly update',
            htmlBody: '<p>Hello</p>',
            plainTextBody: 'Hello',
            fromEmail: 'hi@acme.com',
            fromName: 'Acme',
            ccEmails: ['c@example.com'],
            bccEmails: ['d@example.com'],
            replyTo: 'reply@acme.com',
            ticketId: 't-1',
            attachmentIds: ['f-1'],
            headers: ['X-Campaign' => 'q3'],
        );

        $this->assertSame([
            'fromEmail' => 'hi@acme.com',
            'fromName' => 'Acme',
            'toEmails' => ['a@example.com', 'b@example.com'],
            'ccEmails' => ['c@example.com'],
            'bccEmails' => ['d@example.com'],
            'replyTo' => 'reply@acme.com',
            'subject' => 'Quarterly update',
            'htmlBody' => '<p>Hello</p>',
            'plainTextBody' => 'Hello',
            'ticketId' => 't-1',
            'attachmentIds' => ['f-1'],
            'headers' => ['X-Campaign' => 'q3'],
        ], $http->lastBody());
    }

    public function test_send_omits_absent_optional_fields(): void
    {
        [$client, $http] = $this->client([self::accepted()]);

        $client->emails()->send(toEmails: ['a@example.com'], subject: 'Hi', htmlBody: '<p>Hi</p>');

        $this->assertSame(['toEmails', 'subject', 'htmlBody'], array_keys($http->lastBody()));
    }

    public function test_send_returns_a_typed_result(): void
    {
        [$client] = $this->client([self::accepted()]);

        $result = $client->emails()->send(toEmails: ['a@example.com'], subject: 'Hi', htmlBody: '<p>Hi</p>');

        $this->assertSame('e1', $result->id);
        $this->assertSame(EmailStatus::Pending, $result->status);
        $this->assertSame('pm-1', $result->providerMessageId);
    }

    public function test_send_tolerates_a_status_the_sdk_does_not_know(): void
    {
        [$client] = $this->client([
            FakeHttpClient::json(201, ['id' => 'e1', 'status' => 'quarantined', 'createdAt' => 'now']),
        ]);

        $result = $client->emails()->send(toEmails: ['a@example.com'], subject: 'Hi', htmlBody: '<p>Hi</p>');

        $this->assertSame('quarantined', $result->status);
    }

    public function test_send_rejects_an_empty_recipient_list_without_calling_the_api(): void
    {
        [$client, $http] = $this->client([]);

        try {
            $client->emails()->send(toEmails: [], subject: 'Hi', htmlBody: '<p>Hi</p>');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('toEmails', $e->getMessage());
        }

        $this->assertSame(0, $http->callCount());
    }

    public function test_send_rejects_a_blank_subject_without_calling_the_api(): void
    {
        [$client, $http] = $this->client([]);

        $this->expectException(ValidationException::class);

        try {
            $client->emails()->send(toEmails: ['a@example.com'], subject: '   ', htmlBody: '<p>Hi</p>');
        } finally {
            $this->assertSame(0, $http->callCount());
        }
    }

    public function test_send_rejects_an_email_with_no_body_without_calling_the_api(): void
    {
        [$client, $http] = $this->client([]);

        $this->expectException(ValidationException::class);

        try {
            $client->emails()->send(toEmails: ['a@example.com'], subject: 'Hi');
        } finally {
            $this->assertSame(0, $http->callCount());
        }
    }

    public function test_send_accepts_a_plain_text_only_email(): void
    {
        [$client, $http] = $this->client([self::accepted()]);

        $client->emails()->send(toEmails: ['a@example.com'], subject: 'Hi', plainTextBody: 'Hello');

        $this->assertSame(1, $http->callCount());
    }

    // ------------------------------------------------- send: no double-send

    public function test_send_is_not_retried_after_a_server_error(): void
    {
        // The mail may already have gone out. Retrying could send it twice.
        [$client, $http] = $this->client([
            FakeHttpClient::json(500, ['error' => 'Failed to send email']),
            self::accepted(),
        ], maxRetries: 2);

        $this->expectException(ServerException::class);

        try {
            $client->emails()->send(toEmails: ['a@example.com'], subject: 'Hi', htmlBody: '<p>Hi</p>');
        } finally {
            $this->assertSame(1, $http->callCount(), 'send() must not replay an ambiguous failure');
        }
    }

    public function test_send_is_not_retried_after_a_connection_failure(): void
    {
        [$client, $http] = $this->client([
            new ConnectionException('socket hang up'),
            self::accepted(),
        ], maxRetries: 2);

        $this->expectException(ConnectionException::class);

        try {
            $client->emails()->send(toEmails: ['a@example.com'], subject: 'Hi', htmlBody: '<p>Hi</p>');
        } finally {
            $this->assertSame(1, $http->callCount());
        }
    }

    public function test_send_is_retried_after_a_rate_limit(): void
    {
        // A 429 is raised during validation, before any mail leaves, so replaying
        // it cannot duplicate a send.
        [$client, $http] = $this->client([
            FakeHttpClient::json(429, ['error' => 'rate limit exceeded'], ['retry-after' => '0']),
            self::accepted(),
        ], maxRetries: 2);

        $result = $client->emails()->send(toEmails: ['a@example.com'], subject: 'Hi', htmlBody: '<p>Hi</p>');

        $this->assertSame('e1', $result->id);
        $this->assertSame(2, $http->callCount());
    }

    public function test_a_rate_limit_error_exposes_retry_after(): void
    {
        [$client] = $this->client([
            FakeHttpClient::json(429, ['error' => 'rate limit exceeded'], ['retry-after' => '12']),
        ]);

        try {
            $client->emails()->send(toEmails: ['a@example.com'], subject: 'Hi', htmlBody: '<p>Hi</p>');
            $this->fail('Expected a RateLimitException.');
        } catch (RateLimitException $e) {
            $this->assertSame(12.0, $e->retryAfter);
        }
    }

    // ----------------------------------------------------------- get / list

    public function test_get_requests_the_email_by_id(): void
    {
        [$client, $http] = $this->client([
            FakeHttpClient::json(200, [
                'id' => 'e1',
                'organizationId' => 'o1',
                'fromEmail' => 'hi@acme.com',
                'toEmails' => ['a@example.com'],
                'status' => 'delivered',
                'createdAt' => 'now',
                'updatedAt' => 'now',
            ]),
        ]);

        $email = $client->emails()->get('e1');

        $this->assertSame('https://api.nateq.io/api/v1/outbound-emails/e1', $http->lastRequest()->url);
        $this->assertSame('GET', $http->lastRequest()->method);
        $this->assertSame(EmailStatus::Delivered, $email->status);
        $this->assertTrue($email->status->isDelivered());
    }

    public function test_get_url_encodes_the_id(): void
    {
        [$client, $http] = $this->client([FakeHttpClient::json(200, ['id' => 'x'])]);

        $client->emails()->get('a b/../c');

        $this->assertSame(
            'https://api.nateq.io/api/v1/outbound-emails/a%20b%2F..%2Fc',
            $http->lastRequest()->url,
        );
    }

    public function test_get_rejects_a_blank_id_without_calling_the_api(): void
    {
        [$client, $http] = $this->client([]);

        $this->expectException(ValidationException::class);

        try {
            $client->emails()->get('  ');
        } finally {
            $this->assertSame(0, $http->callCount());
        }
    }

    public function test_get_is_retried_after_a_server_error(): void
    {
        // Reads have no side effects, so replaying one is always safe.
        [$client, $http] = $this->client([
            FakeHttpClient::json(500, ['error' => 'boom']),
            FakeHttpClient::json(200, ['id' => 'e1']),
        ], maxRetries: 2);

        $email = $client->emails()->get('e1');

        $this->assertSame('e1', $email->id);
        $this->assertSame(2, $http->callCount());
    }

    public function test_list_sends_filters_as_query_parameters(): void
    {
        [$client, $http] = $this->client([FakeHttpClient::json(200, ['emails' => [], 'total' => 0])]);

        $client->emails()->list(
            status: EmailStatus::Bounced,
            toEmail: 'a@example.com',
            isAutomatic: false,
            limit: 10,
            offset: 20,
        );

        $url = $http->lastRequest()->url;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('https://api.nateq.io/api/v1/outbound-emails', explode('?', $url)[0]);
        $this->assertSame([
            'status' => 'bounced',
            'toEmail' => 'a@example.com',
            'isAutomatic' => 'false',
            'limit' => '10',
            'offset' => '20',
        ], $query);
    }

    public function test_list_accepts_a_status_string(): void
    {
        [$client, $http] = $this->client([FakeHttpClient::json(200, ['emails' => [], 'total' => 0])]);

        $client->emails()->list(status: 'sent');

        $this->assertStringContainsString('status=sent', $http->lastRequest()->url);
    }

    public function test_list_sends_no_query_string_when_unfiltered(): void
    {
        [$client, $http] = $this->client([FakeHttpClient::json(200, ['emails' => [], 'total' => 0])]);

        $client->emails()->list();

        $this->assertSame('https://api.nateq.io/api/v1/outbound-emails', $http->lastRequest()->url);
    }

    public function test_list_returns_a_countable_iterable_page(): void
    {
        [$client] = $this->client([
            FakeHttpClient::json(200, [
                'emails' => [
                    ['id' => 'e1', 'status' => 'sent'],
                    ['id' => 'e2', 'status' => 'delivered'],
                ],
                'total' => 5,
                'limit' => 2,
                'offset' => 0,
            ]),
        ]);

        $page = $client->emails()->list(limit: 2);

        $this->assertCount(2, $page);
        $this->assertSame(5, $page->total);
        $this->assertTrue($page->hasMore());
        $this->assertSame(2, $page->nextOffset());
        $this->assertSame(['e1', 'e2'], array_map(fn ($e) => $e->id, iterator_to_array($page)));
    }

    public function test_the_last_page_reports_no_more(): void
    {
        [$client] = $this->client([
            FakeHttpClient::json(200, [
                'emails' => [['id' => 'e5']],
                'total' => 5,
                'limit' => 2,
                'offset' => 4,
            ]),
        ]);

        $page = $client->emails()->list(limit: 2, offset: 4);

        $this->assertFalse($page->hasMore());
        $this->assertNull($page->nextOffset());
    }

    // --------------------------------------------------------- error mapping

    #[DataProvider('errorStatuses')]
    public function test_it_maps_status_codes_onto_exception_types(int $status, string $expected): void
    {
        [$client] = $this->client([FakeHttpClient::json($status, ['error' => 'nope'])]);

        $this->expectException($expected);

        $client->emails()->get('e1');
    }

    public static function errorStatuses(): array
    {
        return [
            '401' => [401, AuthenticationException::class],
            '403' => [403, PermissionException::class],
            '404' => [404, NotFoundException::class],
            '400' => [400, ValidationException::class],
            '422' => [422, ValidationException::class],
            '429' => [429, RateLimitException::class],
        ];
    }

    public function test_it_parses_the_enveloped_error_shape(): void
    {
        // What the auth middleware returns.
        [$client] = $this->client([
            FakeHttpClient::json(403, [
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_SCOPE',
                    'message' => 'This API key lacks the emails:send scope',
                    'details' => ['required' => 'emails:send'],
                ],
            ], ['x-request-id' => 'req-99']),
        ]);

        try {
            $client->emails()->get('e1');
            $this->fail('Expected a PermissionException.');
        } catch (PermissionException $e) {
            $this->assertSame('This API key lacks the emails:send scope', $e->getMessage());
            $this->assertSame('INSUFFICIENT_SCOPE', $e->errorCode);
            $this->assertSame(['required' => 'emails:send'], $e->details);
            $this->assertSame('req-99', $e->requestId);
            $this->assertSame(403, $e->status);
        }
    }

    public function test_it_parses_the_flat_error_shape(): void
    {
        // What the outbound-email handler returns.
        [$client] = $this->client([
            FakeHttpClient::json(500, ['error' => 'Failed to send email', 'details' => 'smtp down']),
        ]);

        try {
            $client->emails()->send(toEmails: ['a@example.com'], subject: 'Hi', htmlBody: '<p>Hi</p>');
            $this->fail('Expected a ServerException.');
        } catch (ServerException $e) {
            $this->assertSame('Failed to send email', $e->getMessage());
            $this->assertSame('smtp down', $e->details);
        }
    }

    public function test_it_survives_an_error_body_that_is_not_json(): void
    {
        [$client] = $this->client([new \Nateq\Sdk\Http\Response(502, [], '<html>bad gateway</html>')]);

        try {
            $client->emails()->get('e1');
            $this->fail('Expected a ServerException.');
        } catch (ServerException $e) {
            $this->assertStringContainsString('502', $e->getMessage());
        }
    }

    public function test_an_error_message_never_carries_the_key(): void
    {
        // A misbehaving gateway that echoes the Authorization header must not turn
        // an exception — which frameworks render and ship to log aggregators —
        // into a credential disclosure.
        [$client] = $this->client([
            FakeHttpClient::json(400, ['error' => 'Bad auth header: Bearer '.self::KEY]),
        ]);

        try {
            $client->emails()->get('e1');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertStringContainsString('[REDACTED]', $e->getMessage());
        }
    }
}
