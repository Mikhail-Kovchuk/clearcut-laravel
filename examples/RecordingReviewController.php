<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ClearcutJob;
use Clearcut\Video\ClearcutClient;
use Clearcut\Video\Data\JobRequest;
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
            ]);
        } catch (ClearcutUnavailableException $e) {
            // 503, not 500: the panel should say "try again", not "something
            // broke". Nothing about the request was wrong.
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    /**
     * Start a detection run for review. Returns a job to poll.
     */
    public function analyze(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_key' => 'required|string|max:1024',
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
            'counts' => $proposal->counts(),
            'fully_reviewed' => $proposal->fullyReviewed(),
            'regions' => array_map(static fn ($region) => [
                'name' => $region->name,
                'x' => $region->x, 'y' => $region->y,
                'w' => $region->w, 'h' => $region->h,
                't0' => $region->t0, 't1' => $region->t1,
                'decision' => $region->decision,
                'source' => $region->source,
                'reason' => $region->reason,
            ], $proposal->regions),
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
     * Encode what the reviewer kept.
     */
    public function apply(Request $request, ClearcutJob $record): JsonResponse
    {
        $validated = $request->validate([
            'redaction_style' => 'required|in:blur,solid,pixelate',
            'brand' => 'nullable|string|max:64',
            'mark_type' => 'required|in:logo,text,none',
            'allow_undecided' => 'boolean',
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
}
