<?php

declare(strict_types=1);

namespace Nateq\Sdk\Tests;

use Nateq\Sdk\Exceptions\ConfigurationException;
use Nateq\Sdk\Nateq;
use Nateq\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const KEY = 'tg_live_'.self::FILLER;
    private const FILLER = 'abcdefghijklmnopqrstuvwxyz0123456789abcdefghij';

    protected function setUp(): void
    {
        unset($_ENV['NATEQ_API_KEY'], $_ENV['NATEQ_BASE_URL'], $_SERVER['NATEQ_API_KEY'], $_SERVER['NATEQ_BASE_URL']);
        putenv('NATEQ_API_KEY');
        putenv('NATEQ_BASE_URL');
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    public function test_it_reads_the_key_from_the_environment(): void
    {
        $_ENV['NATEQ_API_KEY'] = self::KEY;

        $client = new Nateq(httpClient: new FakeHttpClient());

        $this->assertSame(substr(self::KEY, -4), $client->apiKeyLast4());
    }

    public function test_an_explicit_key_beats_the_environment(): void
    {
        $_ENV['NATEQ_API_KEY'] = 'tg_live_'.str_repeat('z', 48);

        $client = new Nateq(apiKey: self::KEY, httpClient: new FakeHttpClient());

        $this->assertSame(substr(self::KEY, -4), $client->apiKeyLast4());
    }

    public function test_it_rejects_a_missing_key(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('NATEQ_API_KEY');

        new Nateq(httpClient: new FakeHttpClient());
    }

    public function test_it_rejects_a_key_with_surrounding_whitespace(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('whitespace');

        new Nateq(apiKey: self::KEY."\n", httpClient: new FakeHttpClient());
    }

    public function test_it_rejects_a_key_with_the_wrong_prefix(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('tg_live_ or tg_test_');

        new Nateq(apiKey: 'sk_live_'.self::FILLER, httpClient: new FakeHttpClient());
    }

    public function test_it_rejects_a_key_that_is_too_short(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('too short');

        new Nateq(apiKey: 'tg_live_abc', httpClient: new FakeHttpClient());
    }

    public function test_it_accepts_a_test_key(): void
    {
        $client = new Nateq(apiKey: 'tg_test_'.self::FILLER, httpClient: new FakeHttpClient());

        $this->assertSame(Nateq::DEFAULT_BASE_URL, $client->baseUrl);
    }

    public function test_it_defaults_to_the_production_base_url(): void
    {
        $client = new Nateq(apiKey: self::KEY, httpClient: new FakeHttpClient());

        $this->assertSame('https://api.nateq.io/api/v1', $client->baseUrl);
    }

    public function test_it_reads_the_base_url_from_the_environment(): void
    {
        $_ENV['NATEQ_BASE_URL'] = 'https://staging.nateq.io/api/v1';

        $client = new Nateq(apiKey: self::KEY, httpClient: new FakeHttpClient());

        $this->assertSame('https://staging.nateq.io/api/v1', $client->baseUrl);
    }

    public function test_it_refuses_to_send_the_key_over_plain_http(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Refusing to send an API key over http://');

        new Nateq(apiKey: self::KEY, baseUrl: 'http://api.example.com/api/v1', httpClient: new FakeHttpClient());
    }

    #[DataProvider('loopbackHosts')]
    public function test_it_allows_plain_http_on_loopback(string $baseUrl): void
    {
        $client = new Nateq(apiKey: self::KEY, baseUrl: $baseUrl, httpClient: new FakeHttpClient());

        $this->assertSame(rtrim($baseUrl, '/'), $client->baseUrl);
    }

    public static function loopbackHosts(): array
    {
        return [
            'localhost' => ['http://localhost:8080/api/v1'],
            'ipv4' => ['http://127.0.0.1:8080/api/v1'],
            'ipv6' => ['http://[::1]:8080/api/v1'],
        ];
    }

    public function test_it_rejects_an_unparsable_base_url(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Invalid baseUrl');

        new Nateq(apiKey: self::KEY, baseUrl: 'not-a-url', httpClient: new FakeHttpClient());
    }

    public function test_it_strips_a_trailing_slash_from_the_base_url(): void
    {
        $client = new Nateq(apiKey: self::KEY, baseUrl: 'https://api.nateq.io/api/v1/', httpClient: new FakeHttpClient());

        $this->assertSame('https://api.nateq.io/api/v1', $client->baseUrl);
    }

    public function test_it_rejects_a_non_positive_timeout(): void
    {
        $this->expectException(ConfigurationException::class);

        new Nateq(apiKey: self::KEY, timeout: 0, httpClient: new FakeHttpClient());
    }

    public function test_it_rejects_negative_retries(): void
    {
        $this->expectException(ConfigurationException::class);

        new Nateq(apiKey: self::KEY, maxRetries: -1, httpClient: new FakeHttpClient());
    }

    public function test_var_dump_does_not_leak_the_key(): void
    {
        $client = new Nateq(apiKey: self::KEY, httpClient: new FakeHttpClient());

        ob_start();
        var_dump($client);
        $output = (string) ob_get_clean();

        $this->assertStringNotContainsString(self::KEY, $output);
        $this->assertStringContainsString('[REDACTED:...'.substr(self::KEY, -4).']', $output);
    }

    public function test_json_encode_does_not_leak_the_key(): void
    {
        $client = new Nateq(apiKey: self::KEY, httpClient: new FakeHttpClient());

        $encoded = json_encode($client);

        $this->assertStringNotContainsString(self::KEY, (string) $encoded);
        $this->assertStringContainsString('REDACTED', (string) $encoded);
    }

    public function test_it_refuses_to_be_serialized(): void
    {
        $client = new Nateq(apiKey: self::KEY, httpClient: new FakeHttpClient());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('cannot be serialized');

        serialize($client);
    }
}
