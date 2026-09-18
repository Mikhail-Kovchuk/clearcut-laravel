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
    public const MODE_FIXED = 'fixed';

    public const MODE_AUTO = 'auto';

    public const MODE_AI = 'ai';

    public const STYLE_BLUR = 'blur';

    public const STYLE_SOLID = 'solid';

    public const STYLE_PIXELATE = 'pixelate';

    public const MARK_LOGO = 'logo';

    public const MARK_TEXT = 'text';

    public const MARK_NONE = 'none';

    /**
     * @param  string  $videoId  the caller's own identifier; appears in output keys and the audit file
     * @param  string  $sourceKey  a key in the service's bucket, NOT a URL
     * @param  string  $mode  fixed | auto | ai — each covers a superset of the one before
     * @param  string  $brand  a slug from the service's configured brands
     * @param  string  $profile  detection speed against thoroughness
     * @param  array<string, mixed>  $profileOverrides  individual profile fields to override
     * @param  array<int, array<string, mixed>>  $regions  reviewed regions; when present, detection is skipped
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
    ) {
        if ($videoId === '' || $sourceKey === '') {
            throw new InvalidArgumentException('videoId and sourceKey are required');
        }

        // The service refuses these too, but failing here names the caller's
        // own line rather than a rejected HTTP request.
        if (! in_array($mode, [self::MODE_FIXED, self::MODE_AUTO, self::MODE_AI], true)) {
            throw new InvalidArgumentException("Unknown mode: {$mode}");
        }

        if ($markType !== self::MARK_NONE && $brand === '') {
            throw new InvalidArgumentException(
                "markType={$markType} needs a brand; pass markType=none for redaction only"
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
            'fixed_preset' => $this->fixedPreset,
            'profile' => $this->profile,
            'reviewed_by_human' => $this->reviewedByHuman,
        ];

        if ($this->regions !== []) {
            $payload['regions'] = $this->regions;
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
}
