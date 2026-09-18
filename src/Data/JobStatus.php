<?php

declare(strict_types=1);

namespace Clearcut\Video\Data;

/**
 * A job's state, as the service reports it.
 *
 * `finished()` and friends exist so callers do not compare strings: a poller
 * written against `$status->state === 'done'` breaks silently if a state is
 * ever renamed, where a method does not.
 */
final class JobStatus
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /**
     * @param  string  $stage  which step is running: downloading, detecting, encoding, uploading
     * @param  float  $progress  0..1 within the current stage, not overall
     * @param  int|null  $regions  how many were covered, once known
     * @param  int  $rejectedRegions  proposed but outside the frame, and therefore not covered
     * @param  array{width: int, height: int}|null  $frame  output geometry; regions are in these pixels
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $state,
        public readonly string $stage = '',
        public readonly float $progress = 0.0,
        public readonly string $kind = 'process',
        public readonly string $videoId = '',
        public readonly ?string $outputKey = null,
        public readonly ?string $auditKey = null,
        public readonly ?int $regions = null,
        public readonly int $rejectedRegions = 0,
        public readonly ?array $frame = null,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            jobId: (string) ($data['job_id'] ?? ''),
            state: (string) ($data['state'] ?? self::QUEUED),
            stage: (string) ($data['stage'] ?? ''),
            progress: (float) ($data['progress'] ?? 0.0),
            kind: (string) ($data['kind'] ?? 'process'),
            videoId: (string) ($data['video_id'] ?? ''),
            outputKey: $data['output_key'] ?? null,
            auditKey: $data['audit_key'] ?? null,
            regions: isset($data['regions']) ? (int) $data['regions'] : null,
            rejectedRegions: (int) ($data['rejected_regions'] ?? 0),
            frame: $data['frame'] ?? null,
            error: $data['error'] ?? null,
        );
    }

    public function finished(): bool
    {
        return in_array($this->state, [self::DONE, self::FAILED, self::CANCELLED], true);
    }

    public function succeeded(): bool
    {
        return $this->state === self::DONE;
    }

    public function failed(): bool
    {
        return $this->state === self::FAILED;
    }

    public function cancelled(): bool
    {
        return $this->state === self::CANCELLED;
    }

    /**
     * Whether any proposed region was rejected for falling outside the frame.
     *
     * Worth surfacing rather than ignoring: a rejected region is one the
     * service could not resolve and deliberately did not guess at, so
     * something on that recording is uncovered and nobody was told.
     */
    public function hasRejectedRegions(): bool
    {
        return $this->rejectedRegions > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'job_id' => $this->jobId,
            'kind' => $this->kind,
            'state' => $this->state,
            'stage' => $this->stage,
            'progress' => $this->progress,
            'video_id' => $this->videoId,
            'output_key' => $this->outputKey,
            'audit_key' => $this->auditKey,
            'regions' => $this->regions,
            'rejected_regions' => $this->rejectedRegions ?: null,
            'frame' => $this->frame,
            'error' => $this->error,
        ], static fn ($value) => $value !== null);
    }
}
