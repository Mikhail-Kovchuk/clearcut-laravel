<?php

declare(strict_types=1);

namespace Clearcut\Video\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Everything after `composer require`, in one command: the config, the
 * examples, the routes wired in, the .env keys, and the migration run.
 *
 * composer cannot do this itself — it runs no package's scripts on install, by
 * design — so installing is two commands, and this is the second.
 *
 * Nothing already there is overwritten without --force. What gets published is
 * application code from then on, and a re-run that replaced a controller
 * somebody had adapted would undo their work without a word.
 */
class InstallCommand extends Command
{
    protected $signature = 'clearcut:install
        {--with-job : Also publish ProcessRecording, the queued job for processing without the UI}
        {--force : Overwrite files that already exist (never the migration)}';

    protected $description = 'Publish the clearcut config and examples, wire the routes and run the migration';

    public function handle(Filesystem $files): int
    {
        $this->components->info('Installing clearcut/clearcut-laravel');

        $this->publish('clearcut-config', config_path('clearcut.php'), 'config/clearcut.php');
        $this->publish('clearcut-models', app_path('Models/ClearcutJob.php'), 'app/Models/ClearcutJob.php');
        $this->publishMigration($files);
        $this->publish('clearcut-controllers', app_path('Http/Controllers/RecordingReviewController.php'), 'app/Http/Controllers/RecordingReviewController.php');
        $this->publish('clearcut-routes', base_path('routes/clearcut.php'), 'routes/clearcut.php');

        if ($this->option('with-job')) {
            $this->publish('clearcut-jobs', app_path('Jobs/ProcessRecording.php'), 'app/Jobs/ProcessRecording.php');
        }

        $routesWired = $this->wireRoutes($files);
        $this->addEnvKeys($files);

        if ($this->confirm('Run the migration now?', true)) {
            $this->call('migrate');
        }

        $this->nextSteps($routesWired);

        return self::SUCCESS;
    }

    private function publish(string $tag, string $target, string $shown): void
    {
        if (is_file($target) && ! $this->option('force')) {
            $this->components->twoColumnDetail($shown, '<fg=yellow>exists, kept</>');

            return;
        }

        $this->callSilently('vendor:publish', ['--tag' => $tag, '--force' => true]);
        $this->components->twoColumnDetail($shown, '<fg=green>published</>');
    }

    /**
     * Checked by what the file is called after its date, not by vendor:publish.
     *
     * vendor:publish tests whether the target exists BEFORE it rewrites the date
     * in the name, so the check never matches and every run adds another copy
     * with a new date — and the second one fails `migrate` on a table that
     * already exists. --force does not apply here for the same reason: it would
     * add a second migration, not replace the first.
     */
    private function publishMigration(Filesystem $files): void
    {
        $existing = $files->glob(database_path('migrations/*_create_clearcut_jobs_table.php'));

        if ($existing !== []) {
            $this->components->twoColumnDetail(
                'database/migrations/'.basename($existing[0]),
                '<fg=yellow>exists, kept</>',
            );

            return;
        }

        $this->callSilently('vendor:publish', ['--tag' => 'clearcut-migrations']);

        $published = $files->glob(database_path('migrations/*_create_clearcut_jobs_table.php'));
        $this->components->twoColumnDetail(
            'database/migrations/'.basename($published[0] ?? 'create_clearcut_jobs_table.php'),
            '<fg=green>published</>',
        );
    }

    /**
     * Loads routes/clearcut.php from routes/api.php, so its paths sit under
     * /api where the React adapter expects them.
     *
     * When routes/api.php is missing — Laravel 11+ ships without one —
     * `install:api` creates it and registers it in bootstrap/app.php. Asked
     * first, because it also installs Sanctum; an application with auth of its
     * own already has an API routes file and never reaches the question.
     */
    private function wireRoutes(Filesystem $files): bool
    {
        $api = base_path('routes/api.php');

        if (! is_file($api)) {
            $this->components->warn('routes/api.php does not exist.');

            if (! $this->confirm('Run install:api to create it? It also installs Laravel Sanctum', true)) {
                return false;
            }

            $this->call('install:api', ['--without-migration-prompt' => true]);

            if (! is_file($api)) {
                return false;
            }
        }

        $contents = $files->get($api);

        if (str_contains($contents, 'clearcut.php')) {
            $this->components->twoColumnDetail('routes/api.php', '<fg=yellow>already loads clearcut.php</>');

            return true;
        }

        $files->append($api, "\nrequire __DIR__.'/clearcut.php';\n");
        $this->components->twoColumnDetail('routes/api.php', '<fg=green>loads routes/clearcut.php</>');

        return true;
    }

    /**
     * Adds the two keys the client needs, empty, where they are missing.
     *
     * Empty on purpose. A made-up URL or token would look configured and fail
     * later as a connection error; an empty token fails at once, naming the
     * variable.
     */
    private function addEnvKeys(Filesystem $files): void
    {
        foreach (['.env', '.env.example'] as $name) {
            $path = base_path($name);

            if (! is_file($path)) {
                continue;
            }

            $contents = $files->get($path);
            $missing = array_filter(
                ['CLEARCUT_URL', 'CLEARCUT_TOKEN'],
                static fn (string $key) => preg_match('/^'.$key.'=/m', $contents) !== 1,
            );

            if ($missing === []) {
                $this->components->twoColumnDetail($name, '<fg=yellow>already has CLEARCUT_*</>');

                continue;
            }

            $lines = implode("\n", array_map(static fn (string $key) => $key.'=', $missing));
            $files->append($path, (str_ends_with($contents, "\n") ? '' : "\n")."\n".$lines."\n");
            $this->components->twoColumnDetail($name, '<fg=green>added '.implode(', ', $missing).'</>');
        }
    }

    private function nextSteps(bool $routesWired): void
    {
        $steps = [
            'Set CLEARCUT_URL and CLEARCUT_TOKEN in .env (the service\'s CLEARCUT_AUTH_TOKEN)',
            'RecordingReviewController: replace App\\Models\\Recording with your recordings model',
            'routes/clearcut.php: replace auth:sanctum and can:process-recordings with your guard and permission',
            'If not every user may see every recording, check a policy in each controller action',
            'Write videoUrl() against the disk that holds your recordings (a 501 stub until then)',
        ];

        if (! $routesWired) {
            array_unshift($steps, 'Load routes/clearcut.php: add  require __DIR__.\'/clearcut.php\';  to your API routes file');
        }

        $this->newLine();
        $this->components->info('Installed. Still to do by hand:');
        $this->components->bulletList($steps);
    }
}
