<?php

declare(strict_types=1);

namespace Clearcut\Video\Data;

use InvalidArgumentException;

/**
 * Several recordings to process under one settings choice.
 *
 * The settings are shared by construction rather than by convention: there is
 * one `JobRequest` acting as the template and a list of recordings, so "one set
 * of settings for all" cannot drift into per-video overrides that the product
 * does not offer and the UI cannot show.
 *
 * The template's own `videoId` and `sourceKey` are ignored — each recording
 * carries its own. Requiring them anyway keeps `JobRequest` a single valid
 * shape instead of one that is half-built in some contexts.
 */
final class BatchRequest
{
    /** What the service accepts in one batch. A UI offering more is wrong. */
    public const MAX_RECORDINGS = 5;

    /**
     * @param  array<int, array{video_id: string, source_key: string}>  $recordings
     * @param  JobRequest  $settings  the template; its videoId and sourceKey are unused
     * @param  string  $kind  process (encode straight away) or analyse (detect and stop for review)
     */
    public function __construct(
        public readonly array $recordings,
        public readonly JobRequest $settings,
        public readonly string $kind = 'process',
    ) {
        if ($recordings === []) {
            throw new InvalidArgumentException('A batch needs at least one recording');
        }

        if (count($recordings) > self::MAX_RECORDINGS) {
            throw new InvalidArgumentException(
                'A batch takes at most '.self::MAX_RECORDINGS.' recordings, got '.count($recordings)
            );
        }

        if (! in_array($kind, ['process', 'analyse'], true)) {
            throw new InvalidArgumentException("Unknown batch kind: {$kind}");
        }

        $keys = [];
        foreach ($recordings as $recording) {
            if (! isset($recording['video_id'], $recording['source_key'])) {
                throw new InvalidArgumentException(
                    'Every recording needs a video_id and a source_key'
                );
            }

            $keys[] = $recording['source_key'];
        }

        // The service rejects this too, but the same recording twice is a
        // caller's mistake worth naming at the caller's own line: both copies
        // would encode, and the second would look like a successful export of
        // something nobody asked for twice.
        if (count(array_unique($keys)) !== count($keys)) {
            throw new InvalidArgumentException(
                'The same recording appears more than once in the batch'
            );
        }
    }

    /**
     * Build from a list of [videoId => sourceKey] pairs.
     *
     * @param  array<string, string>  $videos  video id => source key
     */
    public static function of(array $videos, JobRequest $settings, string $kind = 'process'): self
    {
        $recordings = [];
        foreach ($videos as $videoId => $sourceKey) {
            $recordings[] = ['video_id' => (string) $videoId, 'source_key' => $sourceKey];
        }

        return new self($recordings, $settings, $kind);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'recordings' => array_values($this->recordings),
            'settings' => $this->settings->toArray(),
            'kind' => $this->kind,
        ];
    }
}
