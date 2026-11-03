<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Clearcut\Video\BrandLabels;
use Clearcut\Video\ClearcutClient;
use Clearcut\Video\Data\Brand;
use Clearcut\Video\Exceptions\ClearcutRequestException;
use Clearcut\Video\Exceptions\ClearcutUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * EXAMPLE — published into app/Http/Controllers/ by clearcut:install.
 *
 * The watermark brands: listed, added, changed and removed from the panel.
 * They live in the service's brands file; this passes the change on and says
 * who made it. A logo arrives as a form upload and goes on as base64 PNG.
 *
 * Changing a brand changes what every later recording for it is marked with,
 * so the routes may well want a narrower permission than processing does.
 */
class BrandController extends Controller
{
    private const RULES = [
        'label' => 'required|string|max:80',
        'text' => 'nullable|string|max:60',
        // Checked again by the service, from the bytes: PNG, 10 MB, 4096px.
        'logo' => 'nullable|file|mimetypes:image/png|max:10240',
    ];

    public function __construct(
        private readonly ClearcutClient $clearcut,
        private readonly BrandLabels $labels,
    ) {}

    public function index(): JsonResponse
    {
        try {
            return response()->json([
                'brands' => array_map($this->payload(...), $this->clearcut->brands()),
            ]);
        } catch (ClearcutRequestException $e) {
            return $this->refused($e);
        } catch (ClearcutUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(self::RULES + [
            // The key orders and jobs name the brand by; it cannot change later.
            'slug' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/'],
        ]);

        try {
            $brand = $this->clearcut->createBrand(
                $validated['slug'],
                $validated['label'],
                $validated['text'] ?? null,
                $request->file('logo')?->get(),
            );
        } catch (ClearcutRequestException $e) {
            return $this->refused($e);
        } catch (ClearcutUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        $this->changed('clearcut.brand_created', $request, $brand->slug);

        return response()->json($this->payload($brand), 201);
    }

    /**
     * Change a brand. A POST, not a PUT: PHP reads a file from a multipart
     * body only on POST. No `logo` keeps the one it has; `remove_logo` drops it.
     */
    public function update(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate(self::RULES + ['remove_logo' => 'boolean']);

        try {
            $brand = $this->clearcut->updateBrand(
                $slug,
                $validated['label'],
                $validated['text'] ?? null,
                $request->file('logo')?->get(),
                (bool) ($validated['remove_logo'] ?? false),
            );
        } catch (ClearcutRequestException $e) {
            return $this->refused($e);
        } catch (ClearcutUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        $this->changed('clearcut.brand_updated', $request, $slug);

        return response()->json($this->payload($brand));
    }

    public function destroy(Request $request, string $slug): JsonResponse
    {
        try {
            $this->clearcut->deleteBrand($slug);
        } catch (ClearcutRequestException $e) {
            return $this->refused($e);
        } catch (ClearcutUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        $this->changed('clearcut.brand_deleted', $request, $slug);

        return response()->json(['deleted' => $slug]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Brand $brand): array
    {
        return [
            'slug' => $brand->slug,
            'label' => $brand->label,
            'logo' => $brand->logoAvailable,
            'text' => $brand->text,
            'mark_types' => $brand->availableMarkTypes(),
        ];
    }

    private function changed(string $event, Request $request, string $slug): void
    {
        // Names shown elsewhere are cached for an hour; a renamed brand would
        // keep its old one there until then.
        $this->labels->forget();
        Log::channel(config('clearcut.log_channel'))
            ->info($event, ['slug' => $slug, 'user' => $request->user()?->getAuthIdentifier()]);
    }

    private function refused(ClearcutRequestException $e): JsonResponse
    {
        // The service's own words where it gave them. Its other statuses
        // become 502: a 401 there is a wrong CLEARCUT_TOKEN, and passed on
        // it would log the user out.
        $detail = $e->body['detail'] ?? null;

        return response()->json(
            ['message' => is_string($detail) ? $detail : $e->getMessage()],
            in_array($e->status, [404, 409, 422], true) ? $e->status : 502,
        );
    }
}
