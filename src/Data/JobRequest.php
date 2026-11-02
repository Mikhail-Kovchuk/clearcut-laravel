<?php

declare(strict_types=1);

namespace Clearcut\Video\Data;

use InvalidArgumentException;

/**
 * What to ask the service to do with one recording.
 *
 * Built with named arguments rather than an array so a typo is a compile-time
 * error instead of a silently ignored key — the service would accept the
 * request and simply not do the thing that was misspelled.
 */
final class JobRequest
{
    /** No redaction at all — for a watermark-only job. */
    public const MODE_NONE = 'none';

    public const MODE_FIXED = 'fixed';

    public const MODE_AUTO = 'auto';

    public const MODE_AI = 'ai';

    public const STYLE_BLUR = 'blur';

    public const STYLE_SOLID = 'solid';

    public const STYLE_PIXELATE = 'pixelate';

    public const MARK_LOGO = 'logo';

    public const MARK_TEXT = 'text';

    public const MARK_NONE = 'none';

    /** The default size. */
    public const SIZE_LARGE = 'large';

    public const SIZE_MEDIUM = 'medium';

    public const SIZE_SMALL = 'small';

    /** The video and its audit become objects in the bucket. */
    public const OUTPUT_S3 = 's3';

    /**
     * Nothing is written to S3: the video and its audit wait on the service
     * until they are streamed to the reviewer's browser, then are deleted there.
     */
    public const OUTPUT_LOCAL = 'local';

    /**
     * @param  string  $videoId  the caller's own identifier; appears in output keys and the audit file
     * @param  string  $sourceKey  a key in the service's bucket, NOT a URL
     * @param  string  $mode  fixed | auto | ai — each covers a superset of the one before
     * @param  string  $brand  a slug from the service's configured brands
     * @param  string  $profile  detection speed against thoroughness
     * @param  array<string, mixed>  $profileOverrides  individual profile fields to override
     * @param  array<int, array<string, mixed>>  $regions  reviewed regions; when present, detection is skipped
     * @param  string|null  $outputDestination  s3 | local; null leaves it to the service's default
     * @param  string  $markSize  large | medium | small — the logo or text, not the frame
     * @param  float  $markSpeed  a multiple of the standard travel speed, 0.25 to 2.0
     * @param  array<int, array{t0: float, t1: float}>|null  $ranges  fragments to look in, seconds; null for the whole recording
     * @param  array<int, array{t0: float, t1: float}>|null  $cuts  what to cut out, seconds; a pass of its own — a job may only cut
     */
    public function __construct(
        public readonly string $videoId,
        public readonly string $sourceKey,
        public readonly string $mode = self::MODE_AUTO,
        public readonly string $redactionStyle = self::STYLE_BLUR,
        public readonly string $brand = '',
        public readonly string $markType = self::MARK_LOGO,
        public readonly string $fixedPreset = 'auto',
        public readonly string $profile = 'balanced',
        public readonly array $profileOverrides = [],
        public readonly array $regions = [],
        public readonly bool $reviewedByHuman = false,
        public readonly ?string $outputDestination = null,
        public readonly string $markSize = self::SIZE_LARGE,
        public readonly float $markSpeed = 1.0,
        public readonly ?array $ranges = null,
        public readonly ?array $cuts = null,
    ) {
        // An empty list is not "the whole recording" or "cut nothing": a
        // caller who chose no fragment chose nothing, and the service refuses
        // it for that reason.
        foreach (['ranges' => $ranges, 'cuts' => $cuts] as $name => $list) {
            if ($list === null) {
                continue;
            }
            if ($list === []) {
                throw new InvalidArgumentException("{$name} is empty; pass null instead");
            }
            foreach ($list as $range) {
                if (! isset($range['t0'], $range['t1']) || $range['t1'] <= $range['t0']) {
                    throw new InvalidArgumentException("Every entry of {$name} needs t0 < t1, in seconds");
                }
            }
        }

        if ($videoId === '' || $sourceKey === '') {
            throw new InvalidArgumentException('videoId and sourceKey are required');
        }

        if ($outputDestination !== null && ! in_array($outputDestination, [self::OUTPUT_S3, self::OUTPUT_LOCAL], true)) {
            throw new InvalidArgumentException("Unknown output destination: {$outputDestination}");
        }

        // The service refuses these too, but failing here names the caller's
        // own line rather than a rejected HTTP request.
        if ($markSpeed < 0.25 || $markSpeed > 2.0) {
            throw new InvalidArgumentException("Mark speed must be 0.25 to 2.0, got {$markSpeed}");
        }

        if (! in_array($markSize, [self::SIZE_LARGE, self::SIZE_MEDIUM, self::SIZE_SMALL], true)) {
            throw new InvalidArgumentException("Unknown mark size: {$markSize}");
        }

        if (! in_array($mode, [self::MODE_NONE, self::MODE_FIXED, self::MODE_AUTO, self::MODE_AI], true)) {
            throw new InvalidArgumentException("Unknown mode: {$mode}");
        }

        if ($markType !== self::MARK_NONE && $brand === '') {
            throw new InvalidArgumentException(
                "markType={$markType} needs a brand; pass markType=none for redaction only"
            );
        }

        // Neither redacting, watermarking nor cutting would re-encode the
        // recording into a copy identical but for a generation of h264 loss.
        if ($mode === self::MODE_NONE && $markType === self::MARK_NONE && $cuts === null) {
            throw new InvalidArgumentException(
                'nothing to do: mode=none skips redaction, markType=none skips the watermark, and nothing is cut'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'video_id' => $this->videoId,
            'source_key' => $this->sourceKey,
            'mode' => $this->mode,
            'redaction_style' => $this->redactionStyle,
            'partner' => $this->brand,
            'mark_type' => $this->markType,
            'mark_size' => $this->markSize,
            'mark_speed' => $this->markSpeed,
            'fixed_preset' => $this->fixedPreset,
            'profile' => $this->profile,
            'reviewed_by_human' => $this->reviewedByHuman,
        ];

        if ($this->regions !== []) {
            $payload['regions'] = $this->regions;
        }

        if ($this->outputDestination !== null) {
            $payload['output_destination'] = $this->outputDestination;
        }

        foreach (['ranges' => $this->ranges, 'cuts' => $this->cuts] as $name => $list) {
            if ($list !== null) {
                $payload[$name] = self::spans($list);
            }
        }

        // Overrides are merged at the top level, and only the keys actually
        // given: the service treats an absent field as "keep the profile's
        // value", so sending nulls would overwrite the profile being refined.
        foreach ($this->profileOverrides as $key => $value) {
            if ($value !== null) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * Stretches of a recording as the service takes them.
     *
     * @param  array<int, array{t0: float|int|string, t1: float|int|string}>  $spans
     * @return array<int, array{t0: float, t1: float}>
     */
    public static function spans(array $spans): array
    {
        return array_map(
            static fn (array $r) => ['t0' => (float) $r['t0'], 't1' => (float) $r['t1']],
            array_values($spans),
        );
    }
}
