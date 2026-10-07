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
     * @param  string  $stage  which step is running: downloading, sampling, detecting, encoding, uploading
     * @param  float  $progress  0..1 within the current stage, not overall
     * @param  int|null  $regions  how many were covered, once known
     * @param  int  $rejectedRegions  proposed but outside the frame, and therefore not covered
     * @param  array{width: int, height: int}|null  $frame  output geometry; regions are in these pixels
     * @param  string  $batchId  set when this job was submitted as one of several sharing settings
     * @param  float|null  $duration  the recording's length in seconds, null until it has been probed
     * @param  int|null  $framesDone  frames read so far; null when nothing is being counted
     * @param  int|null  $framesTotal  frames to read in total
     * @param  array<string, int>  $bySource  how many regions each layer proposed
     * @param  string|null  $outputDestination  'local' when the output waits on the service for download
     * @param  int|null  $outputSize  bytes of a local output
     * @param  int|null  $outputExpiresAt  unix time a local output is deleted if nobody fetches it
     * @param  bool|null  $redacted  whether a finished encode ran the redaction pass; null until one has
     * @param  bool|null  $watermarked  whether a finished encode burned in a mark; null until one has
     * @param  int|null  $elapsed  seconds since work began, by the service's clock; null while queued
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $state,
        public readonly string $stage = '',
        public readonly float $progress = 0.0,
        public readonly string $kind = 'process',
        public readonly string $videoId = '',
        public readonly string $batchId = '',
        public readonly ?float $duration = null,
        public readonly ?int $framesDone = null,
        public readonly ?int $framesTotal = null,
        public readonly array $bySource = [],
        public readonly ?string $outputKey = null,
        public readonly ?string $auditKey = null,
        public readonly ?int $regions = null,
        public readonly int $rejectedRegions = 0,
        public readonly ?array $frame = null,
        public readonly ?string $error = null,
        public readonly ?string $outputDestination = null,
        public readonly ?int $outputSize = null,
        public readonly ?int $outputExpiresAt = null,
        public readonly ?bool $redacted = null,
        public readonly ?bool $watermarked = null,
        public readonly ?int $elapsed = null,
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
            batchId: (string) ($data['batch_id'] ?? ''),
            // Left null rather than cast to 0.0 when absent: a batch sizing its
            // progress segments must be able to tell "not probed yet" from "a
            // recording of no length".
            duration: isset($data['duration']) ? (float) $data['duration'] : null,
            // Absent while nothing is being counted, which is every stage but
            // detection — null rather than 0, so "not counting" is not read as
            // "none read yet".
            framesDone: isset($data['frames_done']) ? (int) $data['frames_done'] : null,
            framesTotal: isset($data['frames_total']) ? (int) $data['frames_total'] : null,
            bySource: $data['by_source'] ?? [],
            outputKey: $data['output_key'] ?? null,
            auditKey: $data['audit_key'] ?? null,
            regions: isset($data['regions']) ? (int) $data['regions'] : null,
            rejectedRegions: (int) ($data['rejected_regions'] ?? 0),
            frame: $data['frame'] ?? null,
            error: $data['error'] ?? null,
            outputDestination: $data['output_destination'] ?? null,
            outputSize: isset($data['output_size']) ? (int) $data['output_size'] : null,
            outputExpiresAt: isset($data['output_expires_at']) ? (int) $data['output_expires_at'] : null,
            // Null, not false, when absent: an analysis, a running encode or an
            // older service says nothing about the passes, and false would
            // claim a recording was left unredacted.
            redacted: isset($data['redacted']) ? (bool) $data['redacted'] : null,
            watermarked: isset($data['watermarked']) ? (bool) $data['watermarked'] : null,
            elapsed: isset($data['elapsed']) ? (int) $data['elapsed'] : null,
        );
    }

    /**
     * Whether a finished output waits on the service for download rather than
     * sitting in S3. Fetch it with ClearcutClient::streamOutput().
     */
    public function outputIsLocal(): bool
    {
        return $this->outputDestination === JobRequest::OUTPUT_LOCAL;
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
            'batch_id' => $this->batchId ?: null,
            'duration' => $this->duration,
            'frames_done' => $this->framesDone,
            'frames_total' => $this->framesTotal,
            'by_source' => $this->bySource ?: null,
            'output_key' => $this->outputKey,
            'audit_key' => $this->auditKey,
            'regions' => $this->regions,
            'rejected_regions' => $this->rejectedRegions ?: null,
            'frame' => $this->frame,
            'error' => $this->error,
            'output_destination' => $this->outputDestination,
            'output_size' => $this->outputSize,
            'output_expires_at' => $this->outputExpiresAt,
            'redacted' => $this->redacted,
            'watermarked' => $this->watermarked,
            'elapsed' => $this->elapsed,
        ], static fn ($value) => $value !== null);
    }
}
