<?php

declare(strict_types=1);

namespace Clearcut\Video\Data;

/**
 * A named speed/thoroughness setting the service offers.
 *
 * Fetched rather than hardcoded so a retuned profile reaches every client
 * without a release.
 */
final class DetectionProfile
{
    public function __construct(
        public readonly string $name,
        public readonly float $sampleFps,
        public readonly float $dedupeThreshold,
        public readonly bool $usesRapidOcr = true,
        public readonly bool $usesTesseract = true,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            sampleFps: (float) ($data['sample_fps'] ?? 0),
            dedupeThreshold: (float) ($data['dedupe_threshold'] ?? 0),
            usesRapidOcr: (bool) ($data['use_rapidocr'] ?? true),
            usesTesseract: (bool) ($data['use_tesseract'] ?? true),
        );
    }
}
