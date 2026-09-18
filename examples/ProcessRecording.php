<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ClearcutJob;
use Clearcut\Video\ClearcutClient;
use Clearcut\Video\Data\JobRequest;
use Clearcut\Video\Data\JobStatus;
use Clearcut\Video\Exceptions\ClearcutRequestException;
use Clearcut\Video\Exceptions\ClearcutUnavailableException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * EXAMPLE — copy into app/Jobs/ and adapt.
 *
 * Runs one recording through the service and records the outcome. It works
 * against `ClearcutJob` and the standalone table its migration creates, so it
 * references nothing an application might not have — no recordings model, no
 * audit class, no permission names. Wire those in where the comments say.
 *
 * This is NOT package code, and could not be: how a job finds its video, who
 * is allowed to start it and where the audit trail goes are all per
 * application. What it does carry is the shape, and the two mistakes that have
 * cost real time on work like this:
 *
 *   - **The claim is released on EVERY exit path.** Not only success and the
 *     failure handler — an early return that forgets leaves the recording
 *     reporting "in progress" until somebody clears it by hand.
 *
 *   - **A killed process logs nothing.** OOM, a deploy, a worker restart: it
 *     never reaches `failed()`. The only signal is a start line with no
 *     matching finish, so the two are logged as a pair and the gap IS the
 *     diagnostic.
 */
class ProcessRecording implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Once, not three.
     *
     * Encoding is expensive and mostly fails for reasons a retry cannot fix: a
     * corrupt source, a brand that is not configured. Unreachability is the
     * exception, and it is released back onto the queue explicitly below
     * rather than by burning attempts on every kind of failure.
     */
    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(
        public readonly int $clearcutJobId,
        public readonly string $mode = JobRequest::MODE_AUTO,
        public readonly string $brand = '',
    ) {
    }

    public function handle(ClearcutClient $clearcut): void
    {
        $record = ClearcutJob::findOrFail($this->clearcutJobId);

        // Two clicks must not start two encodes of the same recording.
        if (! $record->claim()) {
            Log::info('clearcut.already_claimed', ['job' => $record->id]);

            return;
        }

        // Paired with the finish line below. An entry here with no matching
        // finish means the worker was killed — nothing else produces that.
        Log::info('clearcut.start', [
            'job' => $record->id,
            'mode' => $this->mode,
            'source' => $record->source_key,
        ]);

        try {
            $status = $clearcut->process(new JobRequest(
                videoId: (string) $record->id,
                sourceKey: $record->source_key,
                mode: $this->mode,
                brand: $this->brand,
                markType: $this->brand === '' ? JobRequest::MARK_NONE : JobRequest::MARK_LOGO,
            ));

            $record->update(['service_job_id' => $status->jobId, 'state' => $status->state]);

            $final = $this->pollUntilFinished($clearcut, $status->jobId, $record);
            $record->syncFrom($final);

            // A rejected region is one the service could not resolve and
            // deliberately did not guess at, so something on that recording is
            // uncovered. This is the only place that will ever say so.
            if ($final->hasRejectedRegions()) {
                Log::warning('clearcut.regions_rejected', [
                    'job' => $record->id,
                    'count' => $final->rejectedRegions,
                    'audit_key' => $final->auditKey,
                ]);
            }

            Log::info('clearcut.finish', [
                'job' => $record->id,
                'state' => $final->state,
                'regions' => $final->regions,
            ]);
        } catch (ClearcutUnavailableException $e) {
            // Reachability is temporary. Release rather than fail, so it runs
            // when the service is back — nothing about the request was wrong.
            Log::warning('clearcut.unavailable', [
                'job' => $record->id,
                'error' => $e->getMessage(),
            ]);

            $record->update(['state' => JobStatus::QUEUED, 'error' => $e->getMessage()]);
            $this->release(60);
        } catch (ClearcutRequestException $e) {
            // A rejection will be rejected again. Fail it, with the reason.
            $record->update([
                'state' => JobStatus::FAILED,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            Log::error('clearcut.finish', [
                'job' => $record->id,
                'state' => 'failed',
                'status' => $e->status,
                'error' => $e->getMessage(),
            ]);
        } finally {
            // EVERY path, including the release above and any exception not
            // named here. A stuck claim blocks this recording indefinitely.
            $record->releaseClaim();
        }
    }

    /**
     * Ask for a status until the job stops, or the ceiling is reached.
     */
    private function pollUntilFinished(
        ClearcutClient $clearcut,
        string $serviceJobId,
        ClearcutJob $record,
    ): JobStatus {
        $interval = (int) config('clearcut.poll_interval', 10);
        $deadline = time() + (int) config('clearcut.max_job_seconds', 3600);

        while (time() < $deadline) {
            sleep($interval);

            $status = $clearcut->job($serviceJobId);

            if ($status->finished()) {
                return $status;
            }

            // Keep the row current so a UI can show progress without talking
            // to the service itself.
            $record->update(['stage' => $status->stage, 'progress' => (int) round($status->progress * 100)]);
        }

        // Do not leave it running: the service is holding a worker slot and
        // scratch space for something nobody is waiting for any more.
        $clearcut->cancel($serviceJobId);

        throw new RuntimeException("clearcut job {$serviceJobId} exceeded its time limit");
    }

    /**
     * Called when the job fails outright, including when it is killed after
     * `timeout`.
     *
     * The claim is released here as well as in `finally`, because a terminated
     * process never reaches a `finally` block — that is exactly the case the
     * release above cannot cover.
     */
    public function failed(?Throwable $e): void
    {
        ClearcutJob::query()
            ->whereKey($this->clearcutJobId)
            ->update([
                'claimed_at' => null,
                'state' => JobStatus::FAILED,
                'error' => $e?->getMessage() ?? 'job failed',
                'finished_at' => now(),
            ]);
    }
}
