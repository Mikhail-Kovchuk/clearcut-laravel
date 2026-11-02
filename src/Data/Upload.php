<?php

declare(strict_types=1);

namespace Clearcut\Video\Data;

/**
 * A recording sent from someone's computer, as the service holds it.
 *
 * `sourceKey` is null until the upload is complete and checked; only then can
 * a JobRequest name it, as `upload:<id>`. A job on it must use the local
 * output destination — the service refuses anything else.
 */
final class Upload
{
    public const RECEIVING = 'receiving';

    public const VERIFYING = 'verifying';

    public const READY = 'ready';

    /**
     * @param  int  $received  bytes that have arrived; where the next chunk goes
     * @param  int  $chunkSize  the largest chunk the service accepts
     * @param  int  $expiresAt  unix time it is deleted if nothing touches it
     */
    public function __construct(
        public readonly string $uploadId,
        public readonly string $filename,
        public readonly int $size,
        public readonly int $received,
        public readonly string $state,
        public readonly int $chunkSize,
        public readonly int $expiresAt,
        public readonly ?string $sourceKey = null,
        public readonly ?string $sha256 = null,
        public readonly ?float $duration = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            uploadId: (string) ($data['upload_id'] ?? ''),
            filename: (string) ($data['filename'] ?? ''),
            size: (int) ($data['size'] ?? 0),
            received: (int) ($data['received'] ?? 0),
            state: (string) ($data['state'] ?? self::RECEIVING),
            chunkSize: (int) ($data['chunk_size'] ?? 0),
            expiresAt: (int) ($data['expires_at'] ?? 0),
            sourceKey: $data['source_key'] ?? null,
            sha256: $data['sha256'] ?? null,
            duration: isset($data['duration']) ? (float) $data['duration'] : null,
            width: isset($data['width']) ? (int) $data['width'] : null,
            height: isset($data['height']) ? (int) $data['height'] : null,
        );
    }

    public function ready(): bool
    {
        return $this->state === self::READY;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'upload_id' => $this->uploadId,
            'filename' => $this->filename,
            'size' => $this->size,
            'received' => $this->received,
            'state' => $this->state,
            'chunk_size' => $this->chunkSize,
            'expires_at' => $this->expiresAt,
            'source_key' => $this->sourceKey,
            'sha256' => $this->sha256,
            'duration' => $this->duration,
            'width' => $this->width,
            'height' => $this->height,
        ], static fn ($value) => $value !== null);
    }
}
