<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\WatchClearcutJob;
use App\Models\ClearcutJob;
use Clearcut\Video\ClearcutClient;
use Clearcut\Video\Data\JobRequest;
use Clearcut\Video\Data\JobStatus;
use Clearcut\Video\Exceptions\ClearcutRequestException;
use Clearcut\Video\Exceptions\ClearcutUnavailableException;
use Clearcut\Video\Size;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * EXAMPLE — published into app/Http/Controllers/ by clearcut:install.
 *
 * Recordings sent from the user's own computer rather than taken from the
 * application's storage, and saved back onto it. Each is a `clearcut_jobs`
 * row with `subject_type` 'upload' and no subject: the application has no
 * record of the file, and gets none — the service holds it while it is
 * processed and deletes it once the result is saved.
 *
 * One row per file, created when the upload starts, so the user's list of
 * runs survives a reload and the work goes on with the page closed. The
 * settings are chosen before the upload and the job starts on `complete`,
 * so nothing waits for the user once the last byte is in.
 *
 * The result is always a local output — the service refuses anything else for
 * an upload. Saving it goes through the same `jobs/{record}/output` routes as
 * any local output; releasing it deletes the upload too (see
 * RecordingReviewController::releaseOutput).
 *
 * A user sees and touches only their own uploads.
 */
class UploadController extends Controller
{
    /** `subject_type` of a row whose recording was uploaded. */
    public const SUBJECT = 'upload';

    private const SETTINGS_RULES = [
        'mode' => 'required|in:none,fixed,auto,ai',
        'profile' => 'nullable|in:fast,balanced,thorough',
        'review' => 'boolean',
        'redaction_style' => 'nullable|in:blur,solid,pixelate',
        'brand' => 'nullable|string|max:64',
        'mark_type' => 'nullable|in:logo,text,none',
        'mark_size' => 'nullable|in:large,medium,small',
        'mark_speed' => 'nullable|numeric|between:0.25,2',
        // Fragments to look in, seconds; one file per run, so they are its own.
        'ranges' => 'nullable|array|min:1|max:100',
        'ranges.*.t0' => 'required|numeric|min:0',
        'ranges.*.t1' => 'required|numeric|min:0',
        // What to cut out, seconds; with mode none and no mark, a cut alone.
        'cuts' => 'nullable|array|min:1|max:100',
        'cuts.*.t0' => 'required|numeric|min:0',
        'cuts.*.t1' => 'required|numeric|min:0',
    ];

    public function __construct(private readonly ClearcutClient $clearcut) {}

    /**
     * Start an upload, with the settings its job will run with.
     *
     * Refused here, before the transfer, for anything knowable now: a review
     * with nothing to detect, a file the service will not take.
     */
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filename' => 'required|string|max:1024',
            'size' => 'required|integer|min:1',
        ] + self::SETTINGS_RULES);

        if (! config('clearcut.enabled', true)) {
            return response()->json(['message' => 'Video processing is switched off (CLEARCUT_ENABLED).'], 422);
        }
        if (($validated['review'] ?? false) && $validated['mode'] === 'none') {
            return response()->json(['message' => 'A review needs a detection mode'], 422);
        }

        try {
            $upload = $this->clearcut->createUpload($validated['filename'], (int) $validated['size']);
        } catch (ClearcutRequestException $e) {
            // 413 too large, 409 too many pending, 507 no room: the user's to act on.
            return response()->json(['message' => $e->body['detail'] ?? $e->getMessage()], $e->status);
        } catch (ClearcutUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        $settings = array_intersect_key($validated, self::SETTINGS_RULES)
            + ['review' => false, 'output_destination' => JobRequest::OUTPUT_LOCAL];

        $record = ClearcutJob::create([
            'subject_type' => self::SUBJECT,
            // The service's scheme for an upload. Usable by a job only once
            // the upload is complete; until then this row is 'uploading'.
            'source_key' => "upload:{$upload->uploadId}",
            'source_name' => $upload->filename,
            'settings' => $settings + ['upload_id' => $upload->uploadId, 'size' => $upload->size],
            'state' => 'uploading',
            'stage' => 'uploading',
            'requested_by' => $request->user()?->getAuthIdentifier(),
        ]);

        $this->log()->info('clearcut.upload_start', ['job_row' => $record->id, 'size' => $upload->size, 'user' => $record->requested_by]);

        return response()->json($this->payload($record) + [
            'upload_id' => $upload->uploadId,
            'received' => 0,
            'chunk_size' => $this->chunkSize($upload->chunkSize),
        ], 201);
    }

    /**
     * One chunk, relayed to the service as it arrives. The raw body, not a
     * form: nothing is parsed or held here beyond what the stream buffers.
     */
    public function chunk(Request $request, ClearcutJob $record): JsonResponse
    {
        $this->own($request, $record);
        $offset = filter_var($request->query('offset'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($offset === false) {
            return response()->json(['message' => 'offset must be a whole number of bytes'], 422);
        }
        if ($record->state !== 'uploading') {
            return response()->json(['message' => 'This upload is no longer receiving'], 409);
        }

        $limit = Size::bytes(config('clearcut.upload_chunk_limit', '8MB'));
        if ((int) $request->header('Content-Length') > $limit) {
            return response()->json(['message' => 'The chunk is larger than this server accepts'], 413);
        }

        try {
            $upload = $this->clearcut->uploadChunk($this->uploadId($record), $offset, $request->getContent(true));
        } catch (ClearcutRequestException $e) {
            // A 409 carries `received`: where the browser should carry on from.
            return response()->json(
                ['message' => $e->body['detail'] ?? $e->getMessage()] + array_intersect_key($e->body, ['received' => true]),
                $e->status,
            );
        } catch (ClearcutUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(['received' => $upload->received, 'size' => $upload->size]);
    }

    /**
     * The last byte is in: have the service check the file, then start the
     * job. A failed start leaves the row 'uploading' with the upload complete,
     * so calling this again starts it without sending anything twice.
     */
    public function complete(Request $request, ClearcutJob $record): JsonResponse
    {
        $this->own($request, $record);
        $validated = $request->validate(['sha256' => 'nullable|string|size:64']);
        if ($record->state !== 'uploading') {
            return response()->json(['message' => 'This upload is already complete'], 409);
        }

        try {
            $upload = $this->clearcut->completeUpload($this->uploadId($record), $validated['sha256'] ?? null);
        } catch (ClearcutRequestException $e) {
            $message = $e->body['detail'] ?? $e->getMessage();
            if ($e->status === 422 || $e->isNotFound()) {
                // Rejected and deleted by the service, or gone: nothing to resume.
                $record->update(['state' => JobStatus::FAILED, 'stage' => 'failed', 'error' => $message, 'finished_at' => now()]);
                $this->log()->warning('clearcut.upload_rejected', ['job_row' => $record->id, 'status' => $e->status]);
            }

            return response()->json(['message' => $message], $e->status);
        } catch (ClearcutUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        $settings = $record->settings ?? [];
        $review = (bool) ($settings['review'] ?? false);

        try {
            $job = new JobRequest(
                videoId: (string) $record->id,
                sourceKey: (string) $upload->sourceKey,
                mode: $settings['mode'],
                redactionStyle: $settings['redaction_style'] ?? JobRequest::STYLE_BLUR,
                brand: $settings['brand'] ?? '',
                // An analysis detects only; the mark is chosen at apply.
                markType: $review ? JobRequest::MARK_NONE : ($settings['mark_type'] ?? JobRequest::MARK_NONE),
                profile: $settings['profile'] ?? 'balanced',
                outputDestination: JobRequest::OUTPUT_LOCAL,
                markSize: $settings['mark_size'] ?? JobRequest::SIZE_LARGE,
                markSpeed: (float) ($settings['mark_speed'] ?? 1.0),
                ranges: $settings['ranges'] ?? null,
                cuts: $settings['cuts'] ?? null,
            );
            $status = $review ? $this->clearcut->analyze($job) : $this->clearcut->process($job);
        } catch (\InvalidArgumentException|ClearcutRequestException $e) {
            // Settings the service will not run, found only now. The file is
            // fine; the row fails so the user sees why.
            $record->update(['state' => JobStatus::FAILED, 'stage' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ClearcutUnavailableException $e) {
            $record->update(['error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 503);
        }

        $record->update([
            $review ? 'service_analysis_id' : 'service_job_id' => $status->jobId,
            'state' => $status->state,
            'stage' => $status->stage,
            'error' => null,
        ]);

        $this->log()->info('clearcut.start', ['kind' => $review ? 'analyse' : 'process', 'job_row' => $record->id, 'source' => 'upload', 'user' => $record->requested_by]);
        dispatch(WatchClearcutJob::forRow($record->id));

        return response()->json(['id' => $record->id] + $status->toArray(), 202);
    }

    /** The user's uploads, newest first: what is running, waiting, or done. */
    public function index(Request $request): JsonResponse
    {
        $rows = ClearcutJob::query()
            ->where('subject_type', self::SUBJECT)
            ->where('requested_by', $request->user()?->getAuthIdentifier())
            ->where('created_at', '>', now()->subDays(7))
            ->latest('id')
            ->limit(50)
            ->get();

        return response()->json(['uploads' => $rows->map($this->payload(...))->values()]);
    }

    /**
     * One upload. While it is still arriving, how much the service has, so a
     * browser that lost its connection — or a reload with the file picked
     * again — resumes rather than starting over.
     */
    public function show(Request $request, ClearcutJob $record): JsonResponse
    {
        $this->own($request, $record);
        $payload = $this->payload($record);

        if ($record->state === 'uploading') {
            try {
                $upload = $this->clearcut->upload($this->uploadId($record));
                $payload += [
                    'received' => $upload->received,
                    'upload_state' => $upload->state,
                    'chunk_size' => $this->chunkSize($upload->chunkSize),
                ];
            } catch (ClearcutRequestException $e) {
                if (! $e->isNotFound()) {
                    return response()->json(['message' => $e->getMessage()], $e->status);
                }
                // Expired or deleted on the service: the row can only fail.
                $record->update(['state' => JobStatus::FAILED, 'stage' => 'failed', 'error' => 'The upload expired before it was finished. Send the file again.', 'finished_at' => now()]);
                $payload = $this->payload($record->fresh());
            } catch (ClearcutUnavailableException $e) {
                return response()->json(['message' => $e->getMessage()], 503);
            }
        }

        return response()->json($payload);
    }

    /**
     * Throw an upload away: stop its work, and delete the recording and any
     * unsaved result on the service. Irreversible — a result not saved yet
     * is gone. The row stays, so the list still says what happened.
     */
    public function destroy(Request $request, ClearcutJob $record): JsonResponse
    {
        $this->own($request, $record);

        try {
            if (in_array($record->state, [JobStatus::QUEUED, JobStatus::RUNNING], true)) {
                // False, or a 404, when it ended meanwhile: either is fine.
                $this->forget(fn () => $this->clearcut->cancel((string) ($record->service_job_id ?? $record->service_analysis_id)));
            }
            if ($record->awaitsReview()) {
                $this->forget(fn () => $this->clearcut->discardProposal((string) $record->service_analysis_id));
            }
            if ($record->service_job_id !== null && $record->saved_at === null) {
                $this->clearcut->releaseOutput($record->service_job_id);
            }
            $this->clearcut->deleteUpload($this->uploadId($record));
        } catch (ClearcutUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (ClearcutRequestException $e) {
            return response()->json(['message' => $e->body['detail'] ?? $e->getMessage()], $e->status);
        }

        // Cancelled: whatever was still going, and a result thrown away before
        // it was saved — left "done", it would read as one waiting to be
        // saved, and every save would find nothing. A failed row keeps its
        // state, and its reason; a saved one keeps its record of the save.
        $unsaved = $record->state === JobStatus::DONE && $record->saved_at === null;
        if (in_array($record->state, ['uploading', JobStatus::QUEUED, JobStatus::RUNNING], true) || $unsaved) {
            $record->update(['state' => JobStatus::CANCELLED, 'stage' => 'cancelled', 'finished_at' => $record->finished_at ?? now()]);
        }
        $this->log()->info('clearcut.upload_deleted', ['job_row' => $record->id, 'user' => $request->user()?->getAuthIdentifier()]);

        return response()->json($this->payload($record->fresh()));
    }

    /**
     * A URL the review player can load. Signed and short-lived: a <video>
     * element cannot send the bearer token, so the signature stands in for
     * it on the one route that serves the recording.
     */
    public function videoUrl(Request $request, ClearcutJob $record): JsonResponse
    {
        $this->own($request, $record);

        return response()->json(['url' => URL::temporarySignedRoute(
            'clearcut.upload-video', now()->addMinutes(30), ['record' => $record->id],
        )]);
    }

    /** The recording, streamed with the player's Range passed through. */
    public function video(Request $request, ClearcutJob $record): mixed
    {
        abort_unless($record->subject_type === self::SUBJECT, 404);

        try {
            $video = $this->clearcut->streamUploadVideo($this->uploadId($record), $request->header('Range'));
        } catch (ClearcutRequestException $e) {
            return response()->json(['message' => $e->body['detail'] ?? $e->getMessage()], $e->status);
        } catch (ClearcutUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->stream(function () use ($video) {
            $stream = $video['stream'];
            while (! $stream->eof()) {
                echo $stream->read(256 * 1024);
                flush();
            }
        }, $video['status'], $video['headers'] + ['Cache-Control' => 'private, no-store']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ClearcutJob $record): array
    {
        $settings = $record->settings ?? [];

        return [
            'id' => $record->id,
            'filename' => $record->source_name,
            'size' => $settings['size'] ?? null,
            'state' => $record->state,
            'stage' => $record->stage,
            'progress' => $record->progress / 100,
            'review' => (bool) ($settings['review'] ?? false),
            'awaits_review' => $record->awaitsReview(),
            'settings' => array_intersect_key($settings, self::SETTINGS_RULES),
            'regions' => $record->regions,
            'rejected_regions' => $record->rejected_regions,
            'error' => $record->error,
            'output_destination' => JobRequest::OUTPUT_LOCAL,
            'saved_at' => $record->saved_at?->toIso8601String(),
            'created_at' => $record->created_at?->toIso8601String(),
            'finished_at' => $record->finished_at?->toIso8601String(),
        ];
    }

    /** The smaller of what the service and this server accept. */
    private function chunkSize(int $service): int
    {
        $limit = Size::bytes(config('clearcut.upload_chunk_limit', '8MB'));

        return $service > 0 ? min($service, $limit) : $limit;
    }

    private function uploadId(ClearcutJob $record): string
    {
        return (string) ($record->settings['upload_id'] ?? '');
    }

    /** 404, not 403: whether someone else's upload exists is not this user's business. */
    private function own(Request $request, ClearcutJob $record): void
    {
        abort_unless(
            $record->subject_type === self::SUBJECT
                && (string) $record->requested_by === (string) $request->user()?->getAuthIdentifier(),
            404,
        );
    }

    /** Run a call whose "already gone" is the outcome wanted. */
    private function forget(callable $call): void
    {
        try {
            $call();
        } catch (ClearcutRequestException $e) {
            if (! $e->isNotFound()) {
                throw $e;
            }
        }
    }

    private function log(): \Psr\Log\LoggerInterface
    {
        return Log::channel(config('clearcut.log_channel'));
    }
}
