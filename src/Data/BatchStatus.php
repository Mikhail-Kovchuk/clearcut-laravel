<?php

declare(strict_types=1);

namespace Clearcut\Video\Data;

/**
 * Several recordings submitted under one settings choice.
 *
 * A batch groups jobs; it does not make them one unit of work. Each recording
 * succeeds or fails on its own, and one failure says nothing about the other
 * four — which is why `failed()` below means "something in here failed", not
 * "none of this produced anything".
 *
 * The aggregate figures come from the service rather than being recomputed
 * here: it weights each job by the recording's length, so a four-minute file
 * and a forty-second one are not half the batch each.
 */
final class BatchStatus
{
    /**
     * @param  array<int, JobStatus>  $jobs  in submission order
     * @param  float  $progress  0..1 across the whole batch, weighted by duration
     * @param  int  $finished  how many jobs have reached a terminal state
     */
    public function __construct(
        public readonly string $batchId,
        public readonly string $state,
        public readonly array $jobs,
        public readonly float $progress = 0.0,
        public readonly int $finished = 0,
        public readonly int $total = 0,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $jobs = array_map(JobStatus::fromArray(...), $data['jobs'] ?? []);

        return new self(
            batchId: (string) ($data['batch_id'] ?? ''),
            state: (string) ($data['state'] ?? JobStatus::QUEUED),
            jobs: $jobs,
            progress: (float) ($data['progress'] ?? 0.0),
            finished: (int) ($data['finished'] ?? 0),
            total: (int) ($data['total'] ?? count($jobs)),
        );
    }

    /**
     * Whether every job has stopped, however it stopped.
     */
    public function finished(): bool
    {
        return $this->total > 0 && $this->finished >= $this->total;
    }

    /**
     * Whether every job succeeded. Not the same as `finished()`.
     */
    public function succeeded(): bool
    {
        return $this->state === JobStatus::DONE;
    }

    /**
     * Whether ANY job failed — including beside four that did not.
     *
     * Deliberately not "the batch failed": the successful encodes are real
     * outputs and should be kept. A caller acting on this reports the failures
     * rather than discarding the rest.
     */
    public function failed(): bool
    {
        return $this->state === JobStatus::FAILED;
    }

    /**
     * The jobs that did not succeed, for reporting which recordings need redoing.
     *
     * @return array<int, JobStatus>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->jobs,
            static fn (JobStatus $job) => $job->failed(),
        ));
    }

    /**
     * @return array<int, JobStatus>
     */
    public function successes(): array
    {
        return array_values(array_filter(
            $this->jobs,
            static fn (JobStatus $job) => $job->succeeded(),
        ));
    }

    public function job(string $jobId): ?JobStatus
    {
        foreach ($this->jobs as $job) {
            if ($job->jobId === $jobId) {
                return $job;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'state' => $this->state,
            'progress' => $this->progress,
            'finished' => $this->finished,
            'total' => $this->total,
            'jobs' => array_map(static fn (JobStatus $job) => $job->toArray(), $this->jobs),
        ];
    }
}
