<?php

declare(strict_types=1);

namespace Clearcut\Video;

use Clearcut\Video\Console\InstallCommand;
use Clearcut\Video\Exceptions\ClearcutException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the client as a singleton, publishes the config and the examples,
 * and registers `clearcut:install`, which publishes them all in one go.
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

            // Published, never loaded from vendor/: the table is the
            // application's to change, and a migration that runs from here
            // would change under it on the next composer update. The date in
            // the name is replaced with the moment of publishing where the
            // application has database.migrations.update_date_on_publish on,
            // as new installs do; otherwise it stands, and still orders after
            // the framework's own tables.
            $this->publishesMigrations([
                __DIR__.'/../examples/migration_create_clearcut_jobs_table.php' => database_path('migrations/2026_01_01_000000_create_clearcut_jobs_table.php'),
            ], 'clearcut-migrations');

            // The rest of the examples, same reasoning: once published they are
            // application code, and each has lines to adapt before it runs.
            $this->publishes([
                __DIR__.'/../examples/ClearcutJob.php' => app_path('Models/ClearcutJob.php'),
            ], 'clearcut-models');

            $this->publishes([
                __DIR__.'/../examples/RecordingReviewController.php' => app_path('Http/Controllers/RecordingReviewController.php'),
            ], 'clearcut-controllers');

            // A file of its own rather than lines pasted into routes/api.php,
            // so the application's routes and these stay apart and the `use`
            // lines cannot collide.
            $this->publishes([
                __DIR__.'/../examples/routes.php' => base_path('routes/clearcut.php'),
            ], 'clearcut-routes');

            $this->publishes([
                __DIR__.'/../examples/ProcessRecording.php' => app_path('Jobs/ProcessRecording.php'),
            ], 'clearcut-jobs');

            $this->commands([InstallCommand::class]);
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
