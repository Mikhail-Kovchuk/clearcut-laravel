<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\RecordingReviewController;
use App\Models\ClearcutJob;
use App\Services\ClearcutFiles;
use Clearcut\Video\Data\BatchRequest;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * EXAMPLE — published into app/Console/Commands/ by clearcut:install.
 *
 * Start processing from the console. Goes through the controller actions, so
 * the checks and the behaviour match the panel.
 */
class ClearcutProcess extends Command
{
    protected $signature = 'clearcut:process
        {--video=* : Recording id (primary key); repeat for several, at most '.BatchRequest::MAX_RECORDINGS.'}
        {--mode=auto : none | fixed | auto | ai}
        {--style=blur : blur | solid | pixelate}
        {--brand= : Brand slug for the watermark, as the service lists it}
        {--mark=none : logo | text | none}
        {--size=large : large | medium | small}
        {--speed=1 : Watermark travel speed, 0.25 to 2}
        {--profile=balanced : fast | balanced | thorough}
        {--output= : s3 | local — the service\'s default when left out}
        {--review : Detect only; the regions are then reviewed and applied in the panel}
        {--wait : Follow the work here until it ends}
        {--dry-run : Show what would be processed, and with what, without starting}';

    protected $description = 'Redact and/or watermark recordings through clearcut, as the panel does';

    public function handle(RecordingReviewController $controller, ClearcutFiles $files): int
    {
        $ids = array_values(array_unique((array) $this->option('video')));
        if ($ids === []) {
            $this->error('Name the recordings: --video=<id>, repeatable.');

            return self::FAILURE;
        }
        if (count($ids) > BatchRequest::MAX_RECORDINGS) {
            $this->error('At most '.BatchRequest::MAX_RECORDINGS.' recordings run together.');

            return self::FAILURE;
        }

        $recordings = $files->model()::findMany($ids);
        $missing = array_diff(array_map('strval', $ids), $recordings->map(fn (Model $r) => (string) $r->getKey())->all());
        if ($missing !== []) {
            $this->error('No such recording: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $settings = array_filter([
            'mode' => $this->option('mode'),
            'redaction_style' => $this->option('style'),
            'brand' => $this->option('brand'),
            'mark_type' => $this->option('mark'),
            'mark_size' => $this->option('size'),
            'mark_speed' => (float) $this->option('speed'),
            'profile' => $this->option('profile'),
            'output_destination' => $this->option('output'),
        ], fn ($v) => $v !== null && $v !== '');

        $this->table(
            ['Recording', 'File', 'Reads from'],
            $recordings->map(fn (Model $r) => [
                $r->getKey(),
                $files->pathOf($r),
                $files->replaces()
                    ? 'private '.basename($files->originalOf($r) ?? ClearcutFiles::orgPath($files->pathOf($r)))
                    : 'as stored',
            ])->all(),
        );
        $this->line('Settings: '.json_encode($settings + ['review' => (bool) $this->option('review')]));

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was started.');

            return self::SUCCESS;
        }

        $request = Request::create('/', 'POST', $settings);
        if ($recordings->count() === 1) {
            $id = (string) $recordings->first()->getKey();
            $response = $this->option('review')
                ? $controller->analyze($request, $id)
                : $controller->process($request, $id);
        } else {
            $request->merge([
                'video_ids' => $ids,
                'kind' => $this->option('review') ? 'analyse' : 'process',
            ]);
            $response = $controller->startBatch($request);
        }

        $data = $response->getData(true);
        if ($response->getStatusCode() >= 400) {
            $this->error($data['message'] ?? 'Refused ('.$response->getStatusCode().')');

            return self::FAILURE;
        }

        $rows = ClearcutJob::query()
            ->where('subject_type', $recordings->first()->getMorphClass())
            ->whereIn('subject_id', $ids)
            ->latest('id')
            ->take($recordings->count())
            ->get();
        $this->info('Started: row(s) '.$rows->pluck('id')->sort()->implode(', ')
            .(isset($data['batch_id']) ? ", batch {$data['batch_id']}" : '')
            .'. It carries on without this command; the queue worker follows it.');

        return $this->option('wait') ? $this->follow($rows, $files) : self::SUCCESS;
    }

    /** Print each row's progress until all have ended. */
    private function follow(Collection $rows, ClearcutFiles $files): int
    {
        $last = [];
        $deadline = time() + (int) config('clearcut.max_job_seconds', 3600);
        while (true) {
            if (time() > $deadline) {
                $this->warn('Still not finished. Is `php artisan queue:work` running? '
                    .'Nothing here moves the rows; the work itself carries on regardless.');

                return self::FAILURE;
            }

            $rows = $rows->map(fn (ClearcutJob $r) => $r->fresh());
            foreach ($rows as $row) {
                $line = "{$row->state} {$row->stage} {$row->progress}%".($row->placed_at ? ' placed' : '');
                if (($last[$row->id] ?? null) !== $line) {
                    $this->line("row {$row->id}: {$line}");
                    $last[$row->id] = $line;
                }
            }

            $open = $rows->contains(fn (ClearcutJob $r) => in_array($r->state, ['queued', 'running'], true) || $files->placeable($r));
            if (! $open) {
                break;
            }
            // The queue worker moves these rows; this only reads them.
            sleep(5);
        }

        foreach ($rows as $row) {
            $row->state === 'done'
                ? $this->info("row {$row->id}: done — ".match (true) {
                    $row->service_job_id === null => 'waiting for review in the panel',
                    $row->placed_at !== null => 'now '.$files->pathOf($files->findRecording($row->subject_id)),
                    default => 'output '.($row->output_key ?? 'held by the service (local)'),
                })
                : $this->warn("row {$row->id}: {$row->state}".($row->error ? " — {$row->error}" : ''));
        }

        return $rows->every(fn (ClearcutJob $r) => $r->state === 'done') ? self::SUCCESS : self::FAILURE;
    }
}
