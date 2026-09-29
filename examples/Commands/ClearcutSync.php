<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ClearcutJob;
use App\Services\ClearcutFiles;
use Clearcut\Video\ClearcutClient;
use Clearcut\Video\Data\JobStatus;
use Clearcut\Video\Exceptions\ClearcutRequestException;
use Clearcut\Video\Exceptions\ClearcutUnavailableException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * EXAMPLE — published into app/Console/Commands/ by clearcut:install.
 *
 * Settle rows left open with no watch running (a lost queue job, a worker that
 * was down): sync them with the service, place finished encodes, fail jobs the
 * service no longer knows. Never starts work. Safe to schedule.
 */
class ClearcutSync extends Command
{
    protected $signature = 'clearcut:sync
        {--video=* : Only these recordings (primary keys)}
        {--minutes=0 : Only rows untouched for at least this long}
        {--clean-temp : Also delete .part-* files left by interrupted copies and moves (older than an hour)}
        {--dry-run : Show what would be settled, and change nothing}';

    protected $description = 'Settle clearcut rows the background watch lost track of';

    public function handle(ClearcutClient $clearcut, ClearcutFiles $files): int
    {
        $query = ClearcutJob::query()
            ->where('subject_type', (new ($files->model()))->getMorphClass())
            ->where(fn ($q) => $q
                ->whereIn('state', [JobStatus::QUEUED, JobStatus::RUNNING])
                // Finished but not placed.
                ->when($files->replaces(), fn ($q) => $q->orWhere(fn ($q) => $q->where('state', JobStatus::DONE)
                    ->whereNotNull('service_job_id')->whereNotNull('output_key')->whereNull('placed_at'))));

        if ($ids = (array) $this->option('video')) {
            $query->whereIn('subject_id', $ids);
        }
        if (($minutes = (int) $this->option('minutes')) > 0) {
            $query->where('updated_at', '<=', now()->subMinutes($minutes));
        }

        $rows = $query->orderBy('id')->get()
            ->filter(fn (ClearcutJob $r) => $r->state !== JobStatus::DONE || $files->placeable($r));
        $report = [];
        foreach ($rows as $row) {
            $before = $row->state.($row->state === JobStatus::DONE ? ' (not placed)' : '');
            $after = $this->option('dry-run') ? '-' : $this->settle($row, $clearcut, $files);
            $report[] = [$row->id, $row->subject_id, $row->batch_id ?? '-', $before, $after, $row->updated_at];
        }

        if ($report === []) {
            $this->info('Nothing to settle.');
        } else {
            $this->table(['Row', 'Recording', 'Batch', 'Was', 'Now', 'Last change'], $report);
        }

        if ($this->option('clean-temp')) {
            $model = new ($files->model());
            $recordings = $model->newQuery()
                ->when($ids, fn ($q) => $q->whereKey($ids))
                ->whereIn($model->getKeyName(), ClearcutJob::query()
                    ->where('subject_type', $model->getMorphClass())
                    ->select('subject_id'))
                ->get();
            $found = $files->cleanTemp($recordings, (bool) $this->option('dry-run'));
            foreach ($found as $file) {
                $this->line(($this->option('dry-run') ? 'would delete ' : 'deleted ').$file);
            }
            $this->info(count($found).' temporary file(s) '.($this->option('dry-run') ? 'found.' : 'deleted.'));
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was changed.');
        } else {
            Log::channel(config('clearcut.log_channel'))->info('clearcut.sync', ['rows' => count($report)]);
        }

        return self::SUCCESS;
    }

    private function settle(ClearcutJob $row, ClearcutClient $clearcut, ClearcutFiles $files): string
    {
        if ($row->state !== JobStatus::DONE) {
            try {
                $row->syncFrom($clearcut->job((string) ($row->service_job_id ?? $row->service_analysis_id)));
            } catch (ClearcutRequestException $e) {
                if (! $e->isNotFound()) {
                    return 'error: '.$e->getMessage();
                }
                $row->markLost();

                return 'failed (unknown to the service)';
            } catch (ClearcutUnavailableException) {
                return 'unchanged (service unreachable)';
            }
        }

        $files->place($row);
        $fresh = $row->fresh();

        return $fresh->state.($fresh->placed_at ? ' (placed)' : '');
    }
}
