<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ClearcutJob;
use Clearcut\Video\ClearcutClient;
use Clearcut\Video\Data\JobRequest;
use Clearcut\Video\Data\ProposedRegion;
use Clearcut\Video\Exceptions\ClearcutRequestException;
use Clearcut\Video\Exceptions\ClearcutUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EXAMPLE — copy into app/Http/Controllers/ and adapt.
 *
 * The admin panel talks to THIS, never to the service. That is not ceremony:
 * a browser reaching the service directly bypasses every authorisation check
 * here, and the service has no idea who a user is.
 *
 * Three things this layer owns, which the package deliberately does not:
 *
 *   - **Authorisation.** Whose permission names apply is per application, so
 *     the middleware line below is a placeholder to replace.
 *   - **Ownership.** An id in a URL is a claim, not a fact. Without a check,
 *     any authenticated user could work on anyone's recording by changing a
 *     number.
 *   - **Audit.** Who asked for what, in the application's own trail.
 *
 * It works against `ClearcutJob` and its standalone table so it runs in a
 * fresh Laravel install with nothing else present.
 */
class RecordingReviewController extends Controller
{
    public function __construct(private readonly ClearcutClient $clearcut)
    {
        // Replace with your own gate. Left commented rather than invented,
        // because a permission name this application does not have would fail
        // in a way that looks like the package being broken.
        //
        // $this->middleware('permission:recordings.redact');
    }

    /**
     * What this deployment can do — brands and profiles, from the service.
     *
     * Served to the panel rather than hardcoded in it: a brand added to the
     * service's config, or a profile retuned, reaches the UI with no frontend
     * release.
     */
    public function options(): JsonResponse
    {
        try {
            return response()->json([
                'brands' => array_map(static fn ($brand) => [
                    'slug' => $brand->slug,
                    'label' => $brand->label,
                    'mark_types' => $brand->availableMarkTypes(),
                ], $this->clearcut->brands()),

                'profiles' => array_map(static fn ($profile) => [
                    'name' => $profile->name,
                    'sample_fps' => $profile->sampleFps,
                ], $this->clearcut->profiles()),

                // Where a finished video goes by default, and how long a local
                // one waits on the service for its download.
                'output' => $this->clearcut->settings(),
            ]);
        } catch (ClearcutUnavailableException $e) {
            // 503, not 500: the panel should say "try again", not "something
            // broke". Nothing about the request was wrong.
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    /**
     * The mark a brand would burn in, proxied from the service.
     *
     * Proxied rather than linked: the service is not reachable from a browser,
     * and its brand assets live beside its own config. A logo comes back as an
     * image, a wordmark as JSON.
     */
    public function brandPreview(Request $request, string $slug): mixed
    {
        $markType = $request->query('mark_type', 'logo') === 'text' ? 'text' : 'logo';

        try {
            $preview = $this->clearcut->brandPreview($slug, $markType);
        } catch (ClearcutRequestException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        } catch (ClearcutUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        if ($markType === 'text') {
            return response()->json($preview);
        }

        return response($preview['image'], 200, [
            'Content-Type' => 'image/png',
            // Brand assets change when someone edits the service's config,
            // which is rare. An hour stops a batch refetching the same logo.
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Start a detection run for review. Returns a job to poll.
     */
    public function analyze(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_key' => 'required|string|max:1024',
            // No 'none' here: an analysis exists to find regions, and with
            // redaction off there is nothing to find. A watermark-only job
            // goes straight to process().
            'mode' => 'required|in:fixed,auto,ai',
            'profile' => 'nullable|in:fast,balanced,thorough',
        ]);

        $record = ClearcutJob::create([
            'source_key' => $validated['source_key'],
            'requested_by' => $request->user()?->getAuthIdentifier(),
        ]);

        try {
            $status = $this->clearcut->analyze(new JobRequest(
                videoId: (string) $record->id,
                sourceKey: $record->source_key,
                mode: $validated['mode'],
                // Detection only — nothing is encoded, so no brand is needed.
                markType: JobRequest::MARK_NONE,
                profile: $validated['profile'] ?? 'balanced',
            ));
        } catch (\InvalidArgumentException $e) {
            // The DTO refuses impossible combinations before any request is
            // made. That is the caller's mistake, not a server failure — 422,
            // not the 500 an uncaught exception would produce.
            $record->delete();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ClearcutRequestException $e) {
            $record->update(['state' => 'failed', 'error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ClearcutUnavailableException $e) {
            $record->delete();

            return response()->json(['message' => $e->getMessage()], 503);
        }

        $record->update([
            'service_analysis_id' => $status->jobId,
            'state' => $status->state,
        ]);

        // Record who asked, in your own audit trail.

        return response()->json(['id' => $record->id] + $status->toArray(), 202);
    }

    /**
     * Poll an analysis or an encode.
     */
    public function status(ClearcutJob $record): JsonResponse
    {
        $serviceJobId = $record->service_job_id ?? $record->service_analysis_id;

        if ($serviceJobId === null) {
            return response()->json(['message' => 'Nothing has been started yet'], 409);
        }

        try {
            $status = $this->clearcut->job($serviceJobId);
        } catch (ClearcutRequestException $e) {
            // The service forgets finished jobs after an hour. The row here is
            // the authoritative record, so a 404 from the service is not an
            // error if this row already knows the outcome.
            if ($e->isNotFound() && $record->finished_at !== null) {
                return response()->json($record->only([
                    'state', 'stage', 'progress', 'output_key', 'audit_key', 'error',
                ]));
            }

            // Unknown to the service while this row still says it runs: the
            // service restarted or was stopped mid-job, and its registry lives
            // in memory. Nothing will ever finish this row, so it is failed
            // here and its claim released — left as it was, it stays "running"
            // for ever and the panel keeps offering to go back to it.
            if ($e->isNotFound()) {
                $record->update([
                    'state' => 'failed',
                    'stage' => 'failed',
                    'error' => 'The service no longer knows this job: it restarted '
                        .'or was stopped while the job ran. Start it again.',
                    'claimed_at' => null,
                    'finished_at' => now(),
                ]);

                return response()->json($record->only(['state', 'stage', 'progress', 'error']));
            }

            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $record->syncFrom($status);

        return response()->json($status->toArray());
    }

    /**
     * Ask a running job to stop.
     *
     * Safe at any point: the service verifies output before uploading it, so
     * a cancelled job leaves nothing half-written in storage.
     */
    public function cancel(ClearcutJob $record): JsonResponse
    {
        $serviceJobId = $record->service_job_id ?? $record->service_analysis_id;

        if ($serviceJobId === null) {
            return response()->json(['message' => 'Nothing has been started yet'], 409);
        }

        try {
            $stopped = $this->clearcut->cancel($serviceJobId);
        } catch (ClearcutUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        if ($stopped) {
            $record->update([
                'state' => 'cancelled',
                'claimed_at' => null,
                'finished_at' => now(),
            ]);
        }

        // False means it had already finished — a race between the cancel and
        // the work, not an error.
        return response()->json(['cancelled' => $stopped]);
    }

    /**
     * A short-lived URL the browser can play.
     *
     * Adapt to wherever the recording lives. The important part is that this
     * is signed and expires: the review screen holds it for as long as the tab
     * is open, and it points at a recording full of personal data. A permanent
     * link, or a public object, is how that leaks.
     */
    public function videoUrl(ClearcutJob $record): JsonResponse
    {
        // Replace with your own storage disk. For S3:
        //
        //   $url = Storage::disk('s3')->temporaryUrl(
        //       $record->source_key,
        //       now()->addMinutes(30),
        //   );
        //
        // Returning the key alone would be useless to a browser, and returning
        // a permanent URL would outlive the review.
        return response()->json([
            'url' => null,
            'message' => 'Implement videoUrl() against your storage disk',
        ], 501);
    }

    /**
     * The proposed regions, for the review screen.
     */
    public function proposal(ClearcutJob $record): JsonResponse
    {
        if ($record->service_analysis_id === null) {
            return response()->json(['message' => 'No analysis was started'], 409);
        }

        try {
            $proposal = $this->clearcut->proposal($record->service_analysis_id);
        } catch (ClearcutRequestException $e) {
            // A proposal expires after a week. The panel should offer to
            // re-analyse rather than show an error with no way forward.
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'job_id' => $proposal->jobId,
            'frame' => $proposal->frame,
            'duration' => $proposal->duration,
            'counts' => $proposal->counts(),
            'fully_reviewed' => $proposal->fullyReviewed(),
            'regions' => array_map(self::regionPayload(...), $proposal->regions),
            // Surfaced, not hidden: these are regions the service could not
            // resolve and refused to guess at, so the reviewer should know
            // something on this recording is uncovered.
            'rejected' => $proposal->rejected,
        ]);
    }

    /**
     * Record keep/drop verdicts as the reviewer makes them.
     */
    public function decide(Request $request, ClearcutJob $record): JsonResponse
    {
        $validated = $request->validate([
            'decisions' => 'required|array|min:1|max:2000',
            'decisions.*' => 'required|in:kept,dropped,undecided',
        ]);

        if ($record->service_analysis_id === null) {
            return response()->json(['message' => 'No analysis was started'], 409);
        }

        try {
            return response()->json(
                $this->clearcut->decide($record->service_analysis_id, $validated['decisions'])
            );
        } catch (ClearcutRequestException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    /**
     * Add a box the reviewer drew on the frame.
     */
    public function addRegion(Request $request, ClearcutJob $record): JsonResponse
    {
        $validated = $request->validate([
            'x' => 'required|integer|min:0',
            'y' => 'required|integer|min:0',
            'w' => 'required|integer|min:1',
            'h' => 'required|integer|min:1',
            't0' => 'nullable|numeric|min:0',
            't1' => 'nullable|numeric|min:0',
        ]);

        if ($record->service_analysis_id === null) {
            return response()->json(['message' => 'No analysis was started'], 409);
        }

        try {
            $reply = $this->clearcut->addRegion(
                $record->service_analysis_id,
                (int) $validated['x'], (int) $validated['y'],
                (int) $validated['w'], (int) $validated['h'],
                isset($validated['t0']) ? (float) $validated['t0'] : null,
                isset($validated['t1']) ? (float) $validated['t1'] : null,
            );
        } catch (ClearcutRequestException $e) {
            // 422 when the box does not fit the frame; passed through so the
            // screen can put the drawn box back rather than show it as saved.
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        // Record who drew it, in your own audit trail — the service's audit
        // says a reviewer did, not which one.

        return response()->json(self::regionReply($reply), 201);
    }

    /**
     * Reshape or retime one region.
     */
    public function editRegion(Request $request, ClearcutJob $record): JsonResponse
    {
        // `sometimes`, so a field left out stays out: absence means "keep",
        // while an explicit null time means "the whole recording".
        $validated = $request->validate([
            'name' => 'required|string|max:256',
            'x' => 'sometimes|integer|min:0',
            'y' => 'sometimes|integer|min:0',
            'w' => 'sometimes|integer|min:1',
            'h' => 'sometimes|integer|min:1',
            't0' => 'sometimes|nullable|numeric|min:0',
            't1' => 'sometimes|nullable|numeric|min:0',
        ]);

        if ($record->service_analysis_id === null) {
            return response()->json(['message' => 'No analysis was started'], 409);
        }

        $name = $validated['name'];
        unset($validated['name']);

        try {
            $reply = $this->clearcut->editRegion($record->service_analysis_id, $name, $validated);
        } catch (ClearcutRequestException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(self::regionReply($reply));
    }

    /**
     * @param  array{region: ProposedRegion, counts: array<string, int>, fully_reviewed: bool}  $reply
     * @return array<string, mixed>
     */
    private static function regionReply(array $reply): array
    {
        return [
            'region' => self::regionPayload($reply['region']),
            'counts' => $reply['counts'],
            'fully_reviewed' => $reply['fully_reviewed'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function regionPayload(ProposedRegion $region): array
    {
        return [
            'name' => $region->name,
            'x' => $region->x, 'y' => $region->y,
            'w' => $region->w, 'h' => $region->h,
            't0' => $region->t0, 't1' => $region->t1,
            'decision' => $region->decision,
            'source' => $region->source,
            'reason' => $region->reason,
            // The detector's box, once a reviewer has changed it — so the
            // screen can show what was found beside what will be covered.
            'original' => $region->original,
            // A field that scrolled is drawn as these; the box above is only
            // their outline, and drawing it shows far more than is covered.
            'segments' => $region->segments,
        ];
    }

    /**
     * Encode what the reviewer kept.
     */
    public function apply(Request $request, ClearcutJob $record): JsonResponse
    {
        $validated = $request->validate([
            'redaction_style' => 'required|in:blur,solid,pixelate',
            'brand' => 'nullable|string|max:64',
            'mark_type' => 'required|in:logo,text,none',
            'mark_size' => 'nullable|in:large,medium,small',
            'allow_undecided' => 'boolean',
            // "local" writes nothing to S3; see output() below.
            'output_destination' => 'nullable|in:s3,local',
        ]);

        if ($record->service_analysis_id === null) {
            return response()->json(['message' => 'No analysis was started'], 409);
        }

        try {
            $status = $this->clearcut->apply(
                jobId: $record->service_analysis_id,
                redactionStyle: $validated['redaction_style'],
                brand: $validated['brand'] ?? '',
                markType: $validated['mark_type'],
                allowUndecided: (bool) ($validated['allow_undecided'] ?? false),
                outputDestination: $validated['output_destination'] ?? null,
                markSize: $validated['mark_size'] ?? 'large',
            );
        } catch (ClearcutRequestException $e) {
            // A 409 means regions are still undecided and would not be
            // covered. Passed through as-is so the panel can offer to go back,
            // rather than presenting it as a generic failure.
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $record->update([
            'service_job_id' => $status->jobId,
            'state' => $status->state,
            'reviewed_by_human' => true,
        ]);

        return response()->json($status->toArray(), 202);
    }

    /**
     * A finished local output, streamed from the service to the browser.
     *
     * Local outputs are never in S3: the service holds the file until it is
     * released, and this is the only way to it — the browser cannot reach the
     * service (rule 6). Streamed through rather than buffered, since a
     * recording is hundreds of megabytes.
     */
    public function output(ClearcutJob $record): mixed
    {
        if ($record->service_job_id === null) {
            return response()->json(['message' => 'Nothing was encoded for this job'], 409);
        }

        try {
            $output = $this->clearcut->streamOutput($record->service_job_id);
        } catch (ClearcutRequestException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        } catch (ClearcutUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->stream(function () use ($output) {
            $stream = $output['stream'];
            while (! $stream->eof()) {
                echo $stream->read(256 * 1024);
                flush();
            }
        }, 200, [
            'Content-Type' => 'video/mp4',
            // Length and checksum are what let the browser confirm a complete
            // save before it asks for the release.
            'Content-Length' => (string) $output['size'],
            'X-Content-SHA256' => $output['sha256'],
            // Name it after your own record rather than the service's id, so
            // the file on the reviewer's disk says what it is.
            'Content-Disposition' => 'attachment; filename="'.$output['filename'].'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * The audit file for a local output, saved beside the video.
     */
    public function outputAudit(ClearcutJob $record): JsonResponse
    {
        if ($record->service_job_id === null) {
            return response()->json(['message' => 'Nothing was encoded for this job'], 409);
        }

        try {
            return response()->json($this->clearcut->outputAudit($record->service_job_id));
        } catch (ClearcutRequestException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        } catch (ClearcutUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    /**
     * Delete a local output on the service once the browser has saved it.
     *
     * Irreversible — the service holds the only copy — which is why the panel
     * calls this only after the file is written and its size matched.
     */
    public function releaseOutput(ClearcutJob $record): JsonResponse
    {
        if ($record->service_job_id === null) {
            return response()->json(['message' => 'Nothing was encoded for this job'], 409);
        }

        try {
            $released = $this->clearcut->releaseOutput($record->service_job_id);
        } catch (ClearcutUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        // Record who saved it, in your own audit trail: the service knows only
        // that a release was asked for.

        return response()->json(['released' => $released]);
    }
}
