<?php

declare(strict_types=1);

namespace App\Jobs;

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
use Throwable;

/**
 * EXAMPLE — copy into your application and adapt it.
 *
 * This is deliberately NOT part of the package. Everything it does beyond
 * calling the client is bound to one application's schema: which model holds a
 * recording, which columns mark it as in progress, which audit log records who
 * asked. A package cannot know those, and guessing would make it harder to use
 * than to write.
 *
 * What it does show is the shape that matters, and the two mistakes that have
 * cost real time on work like this:
 *
 *   - **A claim must be released on EVERY exit path.** Not just success and
 *     the failure handler: an early return that forgets leaves the recording
 *     reporting "in progress" until somebody notices and clears it by hand.
 *     Here that is a `finally`.
 *
 *   - **A killed process logs nothing.** OOM, a deploy, a worker restart — it
 *     never reaches `failed()`. The only signal is a start line with no
 *     matching finish, so the two are logged as a pair and the gap is the
 *     diagnostic.
 */
class ProcessRecording implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Once, not three times.
     *
     * Encoding is expensive and mostly fails for reasons a retry cannot fix —
     * a corrupt source, a brand that is not configured. `ClearcutUnavailable`
     * is the exception, and it is released back onto the queue explicitly
     * below rather than by burning attempts on everything.
     */
    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(
        public readonly int $recordingId,
        public readonly string $mode = JobRequest::MODE_AUTO,
        public readonly string $brand = '',
    ) {
    }

    public function handle(ClearcutClient $clearcut): void
    {
        /** @var \App\Models\Recording $recording */
        $recording = \App\Models\Recording::findOrFail($this->recordingId);

        // Claim it, and refuse to start if something else already did. Two
        // clicks must not start two encodes of the same file.
        if (! $this->claim($recording)) {
            Log::channel('clearcut')->info('recording.already_claimed', [
                'recording' => $recording->id,
            ]);

            return;
        }

        // Paired with the finish line below. An entry here with no matching
        // finish means the worker was killed — nothing else produces that.
        Log::channel('clearcut')->info('recording.start', [
            'recording' => $recording->id,
            'mode' => $this->mode,
        ]);

        try {
            $status = $clearcut->process(new JobRequest(
                videoId: (string) $recording->id,
                sourceKey: $recording->storage_key,
                mode: $this->mode,
                brand: $this->brand,
                markType: $this->brand === '' ? JobRequest::MARK_NONE : JobRequest::MARK_LOGO,
            ));

            $recording->update(['clearcut_job_id' => $status->jobId]);

            $final = $this->pollUntilFinished($clearcut, $status->jobId);

            if ($final->succeeded()) {
                $this->onSuccess($recording, $final);
            } else {
                $this->onFailure($recording, $final->error ?? $final->state);
            }

            Log::channel('clearcut')->info('recording.finish', [
                'recording' => $recording->id,
                'state' => $final->state,
                'regions' => $final->regions,
            ]);
        } catch (ClearcutUnavailableException $e) {
            // Reachability is temporary. Release rather than fail, so it is
            // retried when the service is back — nothing about the request was
            // wrong.
            Log::channel('clearcut')->warning('recording.service_unavailable', [
                'recording' => $recording->id,
                'error' => $e->getMessage(),
            ]);

            $this->release(60);
        } catch (ClearcutRequestException $e) {
            // A rejection will be rejected again. Fail it, with the reason.
            $this->onFailure($recording, $e->getMessage());

            Log::channel('clearcut')->error('recording.finish', [
                'recording' => $recording->id,
                'state' => 'failed',
                'status' => $e->status,
                'error' => $e->getMessage(),
            ]);
        } finally {
            // EVERY path, including the release above and any exception this
            // does not name. A stuck claim blocks the recording indefinitely.
            $this->releaseClaim($recording);
        }
    }

    /**
     * Ask for a status until the job stops, or the ceiling is reached.
     */
    private function pollUntilFinished(ClearcutClient $clearcut, string $jobId): JobStatus
    {
        $interval = (int) config('clearcut.poll_interval', 10);
        $deadline = time() + (int) config('clearcut.max_job_seconds', 3600);

        while (time() < $deadline) {
            sleep($interval);

            $status = $clearcut->job($jobId);

            if ($status->finished()) {
                return $status;
            }
        }

        // Do not leave it running: the service is holding a worker slot and
        // scratch space for something nobody is waiting for any more.
        $clearcut->cancel($jobId);

        throw new \RuntimeException("clearcut job {$jobId} exceeded its time limit");
    }

    private function onSuccess(object $recording, JobStatus $status): void
    {
        $recording->update([
            'processed_key' => $status->outputKey,
            'audit_key' => $status->auditKey,
            'processed_at' => now(),
        ]);

        // A rejected region is one the service could not resolve and
        // deliberately did not guess at — something on that recording is
        // uncovered, and only this log will ever say so.
        if ($status->hasRejectedRegions()) {
            Log::channel('clearcut')->warning('recording.regions_rejected', [
                'recording' => $recording->id,
                'count' => $status->rejectedRegions,
                'audit_key' => $status->auditKey,
            ]);
        }
    }

    private function onFailure(object $recording, string $reason): void
    {
        $recording->update(['processing_error' => $reason]);
    }

    /**
     * Take the claim, atomically. False when somebody else holds it.
     *
     * A conditional UPDATE rather than a read-then-write: two workers reading
     * "not claimed" at the same moment would both proceed.
     */
    private function claim(object $recording): bool
    {
        return \App\Models\Recording::query()
            ->whereKey($recording->id)
            ->whereNull('clearcut_claimed_at')
            ->update(['clearcut_claimed_at' => now()]) === 1;
    }

    private function releaseClaim(object $recording): void
    {
        \App\Models\Recording::query()
            ->whereKey($recording->id)
            ->update(['clearcut_claimed_at' => null]);
    }

    /**
     * Called when the job fails outright, including when it is killed after
     * `timeout`. The claim is released here too, because `finally` above does
     * not run for a process that was terminated.
     */
    public function failed(?Throwable $e): void
    {
        \App\Models\Recording::query()
            ->whereKey($this->recordingId)
            ->update([
                'clearcut_claimed_at' => null,
                'processing_error' => $e?->getMessage() ?? 'job failed',
            ]);
    }
}
