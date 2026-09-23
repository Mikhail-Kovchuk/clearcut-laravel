<?php

declare(strict_types=1);

namespace Clearcut\Video\Data;

/**
 * What an analysis found, and what the reviewer has decided so far.
 *
 * **A proposal is not a decision.** Regions arrive `undecided`, and an apply
 * step covers only what was explicitly kept. That is the opposite default from
 * automatic processing, where everything found is covered because nobody will
 * look — a review exists precisely so somebody does, and covering what nobody
 * confirmed would make it decorative.
 */
final class AnalysisProposal
{
    /**
     * @param  array<int, ProposedRegion>  $regions
     * @param  array<int, array<string, mixed>>  $rejected  proposed but outside the frame
     * @param  array{width: int, height: int}  $frame  the geometry regions are measured in
     * @param  float  $duration  seconds; 0 for a proposal older than this field
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $videoId,
        public readonly string $sourceKey,
        public readonly array $regions,
        public readonly array $frame,
        public readonly array $rejected = [],
        public readonly string $mode = 'auto',
        public readonly string $profile = 'balanced',
        public readonly float $duration = 0.0,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            jobId: (string) ($data['job_id'] ?? ''),
            videoId: (string) ($data['video_id'] ?? ''),
            sourceKey: (string) ($data['source_key'] ?? ''),
            regions: array_map(ProposedRegion::fromArray(...), $data['regions'] ?? []),
            frame: $data['frame'] ?? ['width' => 0, 'height' => 0],
            rejected: $data['rejected'] ?? [],
            mode: (string) ($data['mode'] ?? 'auto'),
            profile: (string) ($data['profile'] ?? 'balanced'),
            duration: (float) ($data['duration'] ?? 0.0),
        );
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = ['undecided' => 0, 'kept' => 0, 'dropped' => 0];

        foreach ($this->regions as $region) {
            $counts[$region->decision] = ($counts[$region->decision] ?? 0) + 1;
        }

        return $counts;
    }

    public function fullyReviewed(): bool
    {
        foreach ($this->regions as $region) {
            if ($region->undecided()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Regions still awaiting a verdict — the ones that would NOT be covered.
     *
     * @return array<int, ProposedRegion>
     */
    public function undecided(): array
    {
        return array_values(array_filter(
            $this->regions,
            static fn (ProposedRegion $region) => $region->undecided(),
        ));
    }

    /**
     * Whether anything was proposed but could not be resolved.
     *
     * A rejected region is not a covered one. It is reported rather than
     * guessed at, and a caller that ignores this is shipping a recording with
     * something on it nobody looked at.
     */
    public function hasRejected(): bool
    {
        return $this->rejected !== [];
    }
}
