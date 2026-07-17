<?php

declare(strict_types=1);

namespace Nateq\Sdk\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Nateq\Sdk\Exceptions\ConfigurationException;
use Nateq\Sdk\Http\HttpClient;
use Nateq\Sdk\Nateq;

/**
 * Registers the Nateq client in the container.
 *
 * Auto-discovered — nothing to add to config/app.php. Publish the config with:
 *
 *     php artisan vendor:publish --tag=nateq-config
 */
final class NateqServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/nateq.php', 'nateq');

        // Bound as a singleton, and resolved lazily: an app that never sends an
        // email never constructs a client, so a missing key fails at the call
        // site rather than breaking every request during boot.
        $this->app->singleton(Nateq::class, function (Container $app): Nateq {
            /** @var array{api_key?: string|null, base_url?: string|null, timeout?: mixed, max_retries?: mixed} $config */
            $config = $app['config']->get('nateq', []);

            $apiKey = $config['api_key'] ?? null;
            if ($apiKey === null || $apiKey === '') {
                throw new ConfigurationException(
                    'No Nateq API key configured. Set NATEQ_API_KEY in your .env file. '
                    .'If you have just added it and are still seeing this, run `php artisan config:clear`.',
                );
            }

            return new Nateq(
                apiKey: $apiKey,
                baseUrl: $config['base_url'] ?? null,
                timeout: (float) ($config['timeout'] ?? 30),
                maxRetries: (int) ($config['max_retries'] ?? 2),
                // Honour a custom transport if the app has bound one, so tests can
                // swap in a fake with $this->app->bind(HttpClient::class, ...).
                httpClient: $app->bound(HttpClient::class) ? $app->make(HttpClient::class) : null,
            );
        });

        $this->app->alias(Nateq::class, 'nateq');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/nateq.php' => $this->app->configPath('nateq.php'),
            ], 'nateq-config');
        }
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [Nateq::class, 'nateq'];
    }
}
