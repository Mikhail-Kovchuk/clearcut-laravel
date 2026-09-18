<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Clearcut\Video\ClearcutClient;
use Clearcut\Video\Data\JobRequest;
use Clearcut\Video\Exceptions\ClearcutRequestException;
use Clearcut\Video\Exceptions\ClearcutUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EXAMPLE — copy into your application and adapt it.
 *
 * The admin panel talks to THIS, never to clearcut-video. That is not
 * ceremony: a browser reaching the service directly would bypass every
 * authorisation gate below, and the service has no idea who a user is.
 *
 * Three things this layer owns and the package deliberately does not:
 *
 *   - **Authorisation.** Whose permission names these are is per application.
 *   - **Ownership.** A recording id from a request is a claim, not a fact;
 *     every route re-checks that this user may touch this recording.
 *   - **Audit.** Who asked for what, in the application's own trail.
 */
class RecordingReviewController extends Controller
{
    public function __construct(private readonly ClearcutClient $clearcut)
    {
        // Adapt to your own permission names.
        $this->middleware('permission:recordings.redact');
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
                'brands' => array_map(
                    static fn ($brand) => [
                        'slug' => $brand->slug,
                        'label' => $brand->label,
                        'mark_types' => $brand->availableMarkTypes(),
                    ],
                    $this->clearcut->brands(),
                ),
                'profiles' => array_map(
                    static fn ($profile) => [
                        'name' => $profile->name,
                        'sample_fps' => $profile->sampleFps,
                    ],
                    $this->clearcut->profiles(),
                ),
            ]);
        } catch (ClearcutUnavailableException $e) {
            // 503, not 500: the panel should say "try again", not "something
            // broke". Nothing here is wrong with the request.
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    /**
     * Start a detection run for review. Returns a job to poll.
     */
    public function analyze(Request $request, int $recordingId): JsonResponse
    {
        $recording = $this->authorizedRecording($recordingId);

        $validated = $request->validate([
            'mode' => 'required|in:fixed,auto,ai',
            'profile' => 'nullable|in:fast,balanced,thorough',
        ]);

        try {
            $status = $this->clearcut->analyze(new JobRequest(
                videoId: (string) $recording->id,
                sourceKey: $recording->storage_key,
                mode: $validated['mode'],
                // Detection only — nothing is encoded, so no brand is needed.
                markType: JobRequest::MARK_NONE,
                profile: $validated['profile'] ?? 'balanced',
            ));
        } catch (ClearcutRequestException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ClearcutUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        $recording->update(['clearcut_analysis_id' => $status->jobId]);

        // Record who asked, in your own audit trail.
        // AdminAuditLog::record($request->user(), 'recording.analyze', $recording);

        return response()->json($status->toArray(), 202);
    }

    /**
     * Poll an analysis or an encode.
     */
    public function status(int $recordingId, string $jobId): JsonResponse
    {
        $this->authorizedRecording($recordingId);

        try {
            return response()->json($this->clearcut->job($jobId)->toArray());
        } catch (ClearcutRequestException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    /**
     * The proposed regions, for the review screen.
     */
    public function proposal(int $recordingId, string $jobId): JsonResponse
    {
        $this->authorizedRecording($recordingId);

        try {
            $proposal = $this->clearcut->proposal($jobId);
        } catch (ClearcutRequestException $e) {
            // A proposal expires after a week; the panel should offer to
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
            // resolve and did not guess at, so the reviewer should know.
            'rejected' => $proposal->rejected,
        ]);
    }

    /**
     * Record keep/drop verdicts as the reviewer makes them.
     */
    public function decide(Request $request, int $recordingId, string $jobId): JsonResponse
    {
        $this->authorizedRecording($recordingId);

        $validated = $request->validate([
            'decisions' => 'required|array|min:1|max:2000',
            'decisions.*' => 'required|in:kept,dropped,undecided',
        ]);

        try {
            return response()->json(
                $this->clearcut->decide($jobId, $validated['decisions'])
            );
        } catch (ClearcutRequestException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    /**
     * Encode what the reviewer kept.
     */
    public function apply(Request $request, int $recordingId, string $jobId): JsonResponse
    {
        $recording = $this->authorizedRecording($recordingId);

        $validated = $request->validate([
            'redaction_style' => 'required|in:blur,solid,pixelate',
            'brand' => 'nullable|string',
            'mark_type' => 'required|in:logo,text,none',
            'allow_undecided' => 'boolean',
        ]);

        try {
            $status = $this->clearcut->apply(
                jobId: $jobId,
                redactionStyle: $validated['redaction_style'],
                brand: $validated['brand'] ?? '',
                markType: $validated['mark_type'],
                allowUndecided: (bool) ($validated['allow_undecided'] ?? false),
            );
        } catch (ClearcutRequestException $e) {
            // 409 means regions are still undecided and would not be covered.
            // Passed through as-is, so the panel can offer to go back rather
            // than presenting it as a generic failure.
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $recording->update(['clearcut_job_id' => $status->jobId]);

        // AdminAuditLog::record($request->user(), 'recording.redact_apply', $recording);

        return response()->json($status->toArray(), 202);
    }

    /**
     * Resolve the recording and check this user may touch it.
     *
     * An id in a URL is a claim, not proof. Without this, any authenticated
     * user with the permission could analyse and export anyone's recording by
     * changing a number.
     */
    private function authorizedRecording(int $recordingId): object
    {
        $recording = \App\Models\Recording::findOrFail($recordingId);

        $this->authorize('view', $recording);

        return $recording;
    }
}
