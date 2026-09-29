<?php

declare(strict_types=1);

namespace Clearcut\Video;

use Clearcut\Video\Exceptions\ClearcutRequestException;
use Clearcut\Video\Exceptions\ClearcutUnavailableException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The service's brands by slug, cached for an hour — for showing a brand's
 * name or checking one exists without a request each time. A failed lookup
 * is not cached, and answers as if no brand were known.
 */
class BrandLabels
{
    private const CACHE_KEY = 'clearcut.brand_labels';

    public function __construct(private readonly ClearcutClient $clearcut) {}

    /** The brand's label, or the slug itself when none is known. */
    public function labelFor(?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return $slug;
        }

        return $this->all()[$slug] ?? $slug;
    }

    public function has(?string $slug): bool
    {
        return $slug !== null && isset($this->all()[$slug]);
    }

    /** @return array<string, string> slug => label */
    public function all(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $labels = [];
            foreach ($this->clearcut->brands() as $brand) {
                $labels[$brand->slug] = $brand->label;
            }
        } catch (ClearcutUnavailableException|ClearcutRequestException $e) {
            Log::channel(config('clearcut.log_channel'))
                ->warning('clearcut.brands_unavailable', ['error' => $e->getMessage()]);

            return [];
        }

        Cache::put(self::CACHE_KEY, $labels, now()->addHour());

        return $labels;
    }

    /** Drop the cache, after the service's brands changed. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
