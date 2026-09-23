<?php

declare(strict_types=1);

namespace Clearcut\Video\Data;

/**
 * One detected region and its verdict.
 *
 * Coordinates are in the OUTPUT frame's pixels, which is not always the
 * source's — the service may downscale before drawing. `AnalysisProposal::$frame`
 * carries the geometry these are measured against, and a UI that scales boxes
 * against the source size instead will misplace every one of them.
 */
final class ProposedRegion
{
    public const UNDECIDED = 'undecided';

    public const KEPT = 'kept';

    public const DROPPED = 'dropped';

    /**
     * @param  string  $source  which layer proposed it: fixed | ocr | ai | manual
     * @param  string  $reason  what it matched: a pattern name, a label, or an AI verdict
     * @param  float|null  $t0  seconds; null on both bounds means the whole recording
     * @param  array{x: int, y: int, w: int, h: int, t0: float|null, t1: float|null}|null  $original
     *                                                                                             the box as detected, once a reviewer has changed it
     */
    public function __construct(
        public readonly string $name,
        public readonly int $x,
        public readonly int $y,
        public readonly int $w,
        public readonly int $h,
        public readonly string $decision = self::UNDECIDED,
        public readonly string $source = 'ocr',
        public readonly string $reason = '',
        public readonly ?float $t0 = null,
        public readonly ?float $t1 = null,
        public readonly ?array $original = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            x: (int) ($data['x'] ?? 0),
            y: (int) ($data['y'] ?? 0),
            w: (int) ($data['w'] ?? 0),
            h: (int) ($data['h'] ?? 0),
            decision: (string) ($data['decision'] ?? self::UNDECIDED),
            source: (string) ($data['source'] ?? 'ocr'),
            reason: (string) ($data['reason'] ?? ''),
            t0: isset($data['t0']) ? (float) $data['t0'] : null,
            t1: isset($data['t1']) ? (float) $data['t1'] : null,
            original: is_array($data['original'] ?? null) ? $data['original'] : null,
        );
    }

    /**
     * Whether a reviewer reshaped or retimed a box detection proposed.
     */
    public function edited(): bool
    {
        return $this->original !== null;
    }

    /**
     * Whether a reviewer drew this box rather than detection finding it.
     */
    public function drawnByReviewer(): bool
    {
        return $this->source === 'manual';
    }

    public function undecided(): bool
    {
        return $this->decision === self::UNDECIDED;
    }

    public function kept(): bool
    {
        return $this->decision === self::KEPT;
    }

    /**
     * Whether this region is on screen for the whole recording.
     *
     * Fixed regions — a status-bar clock — always are, and the service omits
     * their time bounds so the filter graph can skip a per-frame check.
     */
    public function alwaysVisible(): bool
    {
        return $this->t0 === null && $this->t1 === null;
    }
}
