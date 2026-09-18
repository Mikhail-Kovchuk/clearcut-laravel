<?php

declare(strict_types=1);

namespace Clearcut\Video;

use Clearcut\Video\Exceptions\ClearcutException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the client as a singleton and publishes the config.
 *
 * The provider is the ONLY part of this package that knows it is running in
 * Laravel; `ClearcutClient` takes an HTTP factory and plain strings, so it can
 * be constructed by hand in a test, a console script, or another framework.
 */
class ClearcutServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merged rather than required, so an application that has not
        // published the config still gets working defaults.
        $this->mergeConfigFrom(__DIR__.'/../config/clearcut.php', 'clearcut');

        $this->app->singleton(ClearcutClient::class, function ($app) {
            $config = $app['config']['clearcut'];

            $token = (string) ($config['token'] ?? '');
            if ($token === '') {
                // Fail at resolution with a message naming the variable,
                // rather than at the first request with a 401 that looks like
                // the service rejecting a valid token.
                throw new ClearcutException(
                    'CLEARCUT_TOKEN is not set. The service refuses every '
                    .'request without it.'
                );
            }

            return new ClearcutClient(
                http: $app->make(HttpFactory::class),
                baseUrl: (string) $config['url'],
                token: $token,
                timeout: (int) $config['timeout'],
                retries: (int) $config['retries'],
            );
        });

        $this->app->alias(ClearcutClient::class, 'clearcut');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/clearcut.php' => config_path('clearcut.php'),
            ], 'clearcut-config');
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [ClearcutClient::class, 'clearcut'];
    }
}
