<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ClearcutJob;
use App\Services\ClearcutFiles;
use Clearcut\Video\ClearcutClient;
use Clearcut\Video\Data\JobStatus;
use Clearcut\Video\Exceptions\ClearcutRequestException;
use Clearcut\Video\Exceptions\ClearcutUnavailableException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * EXAMPLE — published into app/Jobs/ by clearcut:install.
 *
 * Polls one clearcut job or batch until it ends and places each finished
 * encode, so work completes with the page closed. Needs a queue worker; on the
 * `sync` driver it polls once and the status routes do the rest.
 *
 * Each attempt is one poll; between attempts the job is released back to the
 * queue instead of sleeping, so it never holds a worker.
 */
class WatchClearcutJob implements ShouldQueue
{
    use Queueable;

    /** Unlimited; bounded by retryUntil(). */
    public int $tries = 0;

    public int $timeout = 120;

    /** Unix time after which the job is given up on. */
    public int $deadline;

    private function __construct(public ?int $rowId, public ?string $batchId)
    {
        $this->deadline = time() + (int) config('clearcut.max_job_seconds', 3600) + 600;
    }

    public static function forRow(int $rowId): self
    {
        return new self($rowId, null);
    }

    public static function forBatch(string $batchId): self
    {
        return new self(null, $batchId);
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->setTimestamp($this->deadline);
    }

    public function handle(ClearcutClient $clearcut, ClearcutFiles $files): void
    {
        $this->batchId !== null
            ? $this->watchBatch($clearcut, $files)
            : $this->watchRow($clearcut, $files, (int) $this->rowId);
    }

    private function watchRow(ClearcutClient $clearcut, ClearcutFiles $files, int $rowId): void
    {
        $row = ClearcutJob::find($rowId);
        if ($row === null || in_array($row->state, [JobStatus::FAILED, JobStatus::CANCELLED], true)) {
            return;
        }

        if ($row->state === JobStatus::DONE) {
            $files->place($row);

            return;
        }

        $serviceId = $row->service_job_id ?? $row->service_analysis_id;
        if ($serviceId === null) {
            $this->release($this->interval());

            return;
        }

        try {
            $status = $clearcut->job($serviceId);
        } catch (ClearcutRequestException $e) {
            if ($e->isNotFound()) {
                $row->markLost();
                $this->log()->warning('clearcut.watch_lost', ['job_row' => $row->id]);

                return;
            }
            throw $e;
        } catch (ClearcutUnavailableException) {
            $this->release($this->interval());

            return;
        }

        $row->syncFrom($status);

        if (! $status->finished()) {
            $this->release($this->interval());

            return;
        }

        $files->place($row);
        $this->log()->info('clearcut.watch_finish', ['job_row' => $row->id, 'state' => $row->fresh()?->state]);
    }

    private function watchBatch(ClearcutClient $clearcut, ClearcutFiles $files): void
    {
        try {
            $batch = $clearcut->batch((string) $this->batchId);
        } catch (ClearcutRequestException $e) {
            if (! $e->isNotFound()) {
                throw $e;
            }

            // Batch forgotten by the service: follow each open row on its own.
            $open = ClearcutJob::where('batch_id', $this->batchId)
                ->whereIn('state', [JobStatus::QUEUED, JobStatus::RUNNING, JobStatus::DONE])
                ->whereNull('placed_at')
                ->pluck('id');
            foreach ($open as $rowId) {
                dispatch(self::forRow($rowId));
            }
            $this->log()->warning('clearcut.watch_batch_lost', ['batch' => $this->batchId, 'rows' => $open->count()]);

            return;
        } catch (ClearcutUnavailableException) {
            $this->release($this->interval());

            return;
        }

        foreach ($batch->jobs as $job) {
            $row = ClearcutJob::where('service_analysis_id', $job->jobId)
                ->orWhere('service_job_id', $job->jobId)
                ->first();
            if ($row === null) {
                continue;
            }
            $row->syncFrom($job);
            $files->place($row);
        }

        if (! $batch->finished()) {
            $this->release($this->interval());

            return;
        }

        $this->log()->info('clearcut.watch_finish', ['batch' => $this->batchId, 'state' => $batch->state]);
    }

    /** Rows are left as they are; `clearcut:sync` or the next status request settles them. */
    public function failed(\Throwable $e): void
    {
        $this->log()->error('clearcut.watch_failed', [
            'job_row' => $this->rowId,
            'batch' => $this->batchId,
            'error' => $e->getMessage(),
        ]);
    }

    private function interval(): int
    {
        return max(5, (int) config('clearcut.watch_interval', 30));
    }

    private function log(): \Psr\Log\LoggerInterface
    {
        return Log::channel(config('clearcut.log_channel'));
    }
}
