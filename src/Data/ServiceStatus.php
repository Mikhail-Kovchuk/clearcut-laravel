<?php

declare(strict_types=1);

namespace Clearcut\Video\Data;

/**
 * What the service is doing now: the jobs running and waiting, in queue order,
 * what ended in the last hour, and the resources they share.
 *
 * The service knows recordings, not orders — a status page that names the
 * order adds it from the application's own job rows.
 */
final class ServiceStatus
{
    /**
     * @param  int  $uptime  seconds since the service started
     * @param  int  $concurrency  jobs run at once; the rest wait
     * @param  int  $cpus  cores the service may use
     * @param  array{processes: int, cache: int, limit: int|null}|null  $memory
     *         bytes, processes and page cache apart: only `processes` can reach the
     *         limit and end in an OOM kill. Null where the host has no cgroup v2.
     * @param  int  $stallAfter  seconds without progress before a job is reported stalled
     * @param  list<JobStatus>  $jobs  running and queued, in the order they run
     * @param  list<JobStatus>  $recent  ended in the last hour, newest first
     */
    public function __construct(
        public readonly int $uptime,
        public readonly int $concurrency,
        public readonly int $cpus,
        public readonly ?array $memory,
        public readonly int $stallAfter,
        public readonly array $jobs,
        public readonly array $recent,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            uptime: (int) ($data['uptime'] ?? 0),
            concurrency: (int) ($data['concurrency'] ?? 1),
            cpus: (int) ($data['cpus'] ?? 1),
            memory: isset($data['memory']) && is_array($data['memory']) ? $data['memory'] : null,
            stallAfter: (int) ($data['stall_after'] ?? 300),
            jobs: array_map(static fn (array $job) => JobStatus::fromArray($job), $data['jobs'] ?? []),
            recent: array_map(static fn (array $job) => JobStatus::fromArray($job), $data['recent'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uptime' => $this->uptime,
            'concurrency' => $this->concurrency,
            'cpus' => $this->cpus,
            'memory' => $this->memory,
            'stall_after' => $this->stallAfter,
            'jobs' => array_map(static fn (JobStatus $job) => $job->toArray(), $this->jobs),
            'recent' => array_map(static fn (JobStatus $job) => $job->toArray(), $this->recent),
        ];
    }
}
