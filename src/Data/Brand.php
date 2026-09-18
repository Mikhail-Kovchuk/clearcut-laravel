<?php

declare(strict_types=1);

namespace Clearcut\Video\Data;

/**
 * A brand the service is configured to watermark with.
 *
 * Fetched rather than hardcoded: which brands exist is the service's own
 * configuration, and a client that guesses will name one that is not there.
 */
final class Brand
{
    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly bool $logoAvailable = false,
        public readonly ?string $text = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            slug: (string) ($data['slug'] ?? ''),
            label: (string) ($data['label'] ?? ''),
            logoAvailable: (bool) ($data['logo_available'] ?? false),
            text: $data['text'] ?? null,
        );
    }

    /**
     * Which mark types this brand can actually produce.
     *
     * A brand configured with text but no logo file cannot be used with
     * `markType=logo`, and asking anyway fails the job. Offering only what
     * works is cheaper than explaining the failure afterwards.
     *
     * @return array<int, string>
     */
    public function availableMarkTypes(): array
    {
        $types = [JobRequest::MARK_NONE];

        if ($this->logoAvailable) {
            $types[] = JobRequest::MARK_LOGO;
        }

        if ($this->text !== null && $this->text !== '') {
            $types[] = JobRequest::MARK_TEXT;
        }

        return $types;
    }
}
