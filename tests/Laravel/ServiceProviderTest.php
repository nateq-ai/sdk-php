<?php

declare(strict_types=1);

namespace Nateq\Sdk\Tests\Laravel;

use Nateq\Sdk\Exceptions\ConfigurationException;
use Nateq\Sdk\Http\HttpClient;
use Nateq\Sdk\Laravel\Facades\Nateq as NateqFacade;
use Nateq\Sdk\Laravel\NateqServiceProvider;
use Nateq\Sdk\Nateq;
use Nateq\Sdk\Tests\Support\FakeHttpClient;
use Nateq\Sdk\Types\EmailStatus;
use Orchestra\Testbench\TestCase;

/**
 * Exercises the package inside a real Laravel container, rather than asserting
 * that it ought to work.
 */
final class ServiceProviderTest extends TestCase
{
    private const KEY = 'tg_live_abcdefghijklmnopqrstuvwxyz0123456789abcdefghij';

    protected function getPackageProviders($app): array
    {
        return [NateqServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Nateq' => NateqFacade::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('nateq.api_key', self::KEY);
    }

    public function test_the_config_is_merged_with_defaults(): void
    {
        $this->assertSame('https://api.nateq.io/api/v1', config('nateq.base_url'));
        $this->assertSame(30, (int) config('nateq.timeout'));
        $this->assertSame(2, (int) config('nateq.max_retries'));
    }

    public function test_the_client_resolves_from_the_container(): void
    {
        $client = $this->app->make(Nateq::class);

        $this->assertInstanceOf(Nateq::class, $client);
        $this->assertSame('https://api.nateq.io/api/v1', $client->baseUrl);
    }

    public function test_the_client_is_a_singleton(): void
    {
        $this->assertSame($this->app->make(Nateq::class), $this->app->make(Nateq::class));
    }

    public function test_it_resolves_by_the_short_alias(): void
    {
        $this->assertInstanceOf(Nateq::class, $this->app->make('nateq'));
    }

    public function test_it_honours_configured_values(): void
    {
        $this->app['config']->set('nateq.base_url', 'https://staging.nateq.io/api/v1');

        $this->assertSame('https://staging.nateq.io/api/v1', $this->app->make(Nateq::class)->baseUrl);
    }

    public function test_a_missing_key_fails_with_an_actionable_message(): void
    {
        $this->app['config']->set('nateq.api_key', null);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('NATEQ_API_KEY');

        $this->app->make(Nateq::class);
    }

    public function test_registration_is_lazy(): void
    {
        // The binding must exist without the client having been constructed:
        // that is what lets an app with no key configured still boot, and keeps
        // a bad key from taking down every request instead of the one send.
        $this->app['config']->set('nateq.api_key', null);

        $this->assertTrue($this->app->bound(Nateq::class));
        $this->assertFalse($this->app->resolved(Nateq::class));
    }

    public function test_a_bound_http_client_is_used(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(201, ['id' => 'e1', 'status' => 'sent', 'createdAt' => 'now']),
        ]);
        $this->app->instance(HttpClient::class, $http);

        $result = $this->app->make(Nateq::class)->emails()->send(
            toEmails: ['a@example.com'],
            subject: 'Hi',
            htmlBody: '<p>Hi</p>',
        );

        $this->assertSame('e1', $result->id);
        $this->assertSame(EmailStatus::Sent, $result->status);
        $this->assertSame(1, $http->callCount());
    }

    public function test_the_facade_forwards_to_the_client(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(201, ['id' => 'e-facade', 'status' => 'pending', 'createdAt' => 'now']),
        ]);
        $this->app->instance(HttpClient::class, $http);

        $result = NateqFacade::emails()->send(
            toEmails: ['a@example.com'],
            subject: 'Hi',
            htmlBody: '<p>Hi</p>',
        );

        $this->assertSame('e-facade', $result->id);
        $this->assertSame('https://api.nateq.io/api/v1/outbound-emails', $http->lastRequest()->url);
    }

    public function test_the_config_is_publishable(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'nateq-config'])->assertSuccessful();

        $this->assertFileExists(config_path('nateq.php'));
        @unlink(config_path('nateq.php'));
    }

    public function test_the_package_is_auto_discovered(): void
    {
        $manifest = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true);

        $this->assertSame(
            [NateqServiceProvider::class],
            $manifest['extra']['laravel']['providers'],
        );
        $this->assertSame(
            ['Nateq' => NateqFacade::class],
            $manifest['extra']['laravel']['aliases'],
        );
    }
}
