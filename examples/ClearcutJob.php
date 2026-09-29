<?php

declare(strict_types=1);

namespace App\Models;

use Clearcut\Video\Data\JobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * EXAMPLE — publish into app/Models/ and adapt:
 *
 *   php artisan vendor:publish --tag=clearcut-models
 *
 * Tracks one processing run against the table `migration_create_clearcut_jobs_table.php`
 * creates. Nothing here references an application's own models: `subject` is a
 * morph, so this attaches to whatever holds recordings without knowing what
 * that is.
 *
 * The claim methods are the part worth copying verbatim. Both are conditional
 * UPDATEs rather than read-then-write, because two workers reading "not
 * claimed" at the same instant would both proceed and encode the same video
 * twice.
 */
class ClearcutJob extends Model
{
    protected $fillable = [
        'subject_type',
        'subject_id',
        'source_key',
        'service_job_id',
        'service_analysis_id',
        'batch_id',
        'settings',
        'state',
        'stage',
        'progress',
        'claimed_at',
        'output_key',
        'audit_key',
        'redacted',
        'watermarked',
        'placed_at',
        'regions',
        'rejected_regions',
        'reviewed_by_human',
        'error',
        'requested_by',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'finished_at' => 'datetime',
            'placed_at' => 'datetime',
            'reviewed_by_human' => 'boolean',
            'progress' => 'integer',
            'regions' => 'integer',
            'rejected_regions' => 'integer',
            'settings' => 'array',
            'redacted' => 'boolean',
            'watermarked' => 'boolean',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Take the claim. False when something else already holds it.
     *
     * A conditional UPDATE, not a read followed by a write: the gap between
     * reading "unclaimed" and writing the claim is exactly long enough for a
     * second worker to read the same thing.
     */
    public function claim(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->whereNull('claimed_at')
            ->update(['claimed_at' => now()]) === 1;
    }

    /**
     * Release the claim.
     *
     * Must be called on EVERY exit path, including the queue's `failed()`
     * handler — a terminated process never reaches a `finally` block, and a
     * claim nobody clears blocks the subject until a human notices.
     */
    public function releaseClaim(): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->update(['claimed_at' => null]);
    }

    /**
     * Copy the service's view of a job onto this row.
     */
    public function syncFrom(JobStatus $status): void
    {
        $this->update([
            'state' => $status->state,
            'stage' => $status->stage,
            'progress' => (int) round($status->progress * 100),
            'output_key' => $status->outputKey ?? $this->output_key,
            'audit_key' => $status->auditKey ?? $this->audit_key,
            'regions' => $status->regions ?? $this->regions,
            // Kept once known: the service stops reporting a job an hour after
            // it ends, and a later sync must not turn "redacted" back to null.
            'redacted' => $status->redacted ?? $this->redacted,
            'watermarked' => $status->watermarked ?? $this->watermarked,
            'rejected_regions' => $status->rejectedRegions,
            'error' => $status->error,
            'finished_at' => $status->finished() ? now() : null,
        ]);
    }

    /**
     * Fail a running row the service no longer knows. Its registry lives in
     * memory, so a restart mid-job loses the job, and nothing would ever
     * finish this row otherwise.
     */
    public function markLost(): void
    {
        $this->update([
            'state' => JobStatus::FAILED,
            'stage' => 'failed',
            'error' => 'The service no longer knows this job: it restarted '
                .'or was stopped while the job ran. Start it again.',
            'claimed_at' => null,
            'finished_at' => now(),
        ]);
    }

    /** Running, or an analysis waiting for its review. */
    public function isOpen(): bool
    {
        return in_array($this->state, [JobStatus::QUEUED, JobStatus::RUNNING], true)
            || $this->awaitsReview();
    }

    /** An analysis finished and not yet applied. */
    public function awaitsReview(): bool
    {
        return $this->state === JobStatus::DONE && $this->service_job_id === null;
    }

    /**
     * Jobs whose claim was never released — a worker died holding it.
     *
     * Running this on a schedule is what stops one killed process blocking a
     * recording forever. The age bound matters: a claim younger than the
     * longest plausible run may belong to a job that is still working.
     */
    public function scopeStuck($query, int $olderThanMinutes = 120)
    {
        return $query
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '<', now()->subMinutes($olderThanMinutes))
            ->whereNotIn('state', [JobStatus::DONE, JobStatus::FAILED, JobStatus::CANCELLED]);
    }
}
