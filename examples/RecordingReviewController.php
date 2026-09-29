<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\WatchClearcutJob;
use App\Models\ClearcutJob;
use App\Services\ClearcutFiles;
use Clearcut\Video\ClearcutClient;
use Clearcut\Video\Data\BatchRequest;
use Clearcut\Video\Data\JobRequest;
use Clearcut\Video\Data\JobStatus;
use Clearcut\Video\Data\ProposedRegion;
use Clearcut\Video\Exceptions\ClearcutRequestException;
use Clearcut\Video\Exceptions\ClearcutUnavailableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * EXAMPLE — published into app/Http/Controllers/ by clearcut:install.
 *
 * The browser talks to THIS, never to the service: the service has no idea
 * who a user is, and a browser reaching it directly bypasses every check here.
 *
 * Recordings are the model named in `clearcut.recordings.model`; `{recording}`
 * and `video_ids` are its primary keys. What this layer leaves to the
 * application:
 *
 *   - **Ownership.** An id in a URL is a claim, not a fact. Where not every
 *     user may see every recording, check a policy in each action.
 *   - **Its own rules** for refusing a recording: refusalFor() below.
 *
 * Every run is followed by WatchClearcutJob on the queue, so it finishes —
 * and with `clearcut.replace_original` is put in place — with the page closed.
 */
class RecordingReviewController extends Controller
{
    private const ENCODE_RULES = [
        'redaction_style' => 'nullable|in:blur,solid,pixelate',
        'brand' => 'nullable|string|max:64',
        'mark_type' => 'nullable|in:logo,text,none',
        'mark_size' => 'nullable|in:large,medium,small',
        'mark_speed' => 'nullable|numeric|between:0.25,2',
        'output_destination' => 'nullable|in:s3,local',
    ];

    public function __construct(
        private readonly ClearcutClient $clearcut,
        private readonly ClearcutFiles $files,
    ) {}

    /**
     * Your application's reason not to process this recording, or null.
     * Checked on every start and on apply. For example: not a video, expired,
     * or already carrying a burned-in mark while `$markType` would add another.
     */
    protected function refusalFor(Model $recording, ?string $markType): ?string
    {
        return null;
    }

    /** Brands, profiles and output settings offered by the service. */
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

                'output' => $this->clearcut->settings(),
            ]);
        } catch (ClearcutUnavailableException $e) {
            // 503: "try again", not "something broke".
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (ClearcutRequestException $e) {
            // 502, not the service's status: its 401 means a wrong
            // CLEARCUT_TOKEN, and passed on it would log the user out.
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /** A brand's mark: a PNG for a logo, JSON for a wordmark. */
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
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Start detection for review. The source key comes from the recording's
     * row, never from the request: a key the browser sends is one it chose.
     */
    public function analyze(Request $request, string $recording): JsonResponse
    {
        $validated = $request->validate([
            // No 'none': an analysis needs a detection mode.
            'mode' => 'required|in:fixed,auto,ai',
            'profile' => 'nullable|in:fast,balanced,thorough',
        ] + self::ENCODE_RULES);

        $recording = $this->files->findRecording($recording);
        if ($refused = $this->refuseStart(collect([$recording]), $validated['mark_type'] ?? null)) {
            return $refused;
        }

        try {
            // Encode choices are stored now so the later apply can use them.
            $record = $this->newRecord($request, $recording, self::settingsFrom($validated, review: true));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        try {
            $status = $this->clearcut->analyze(new JobRequest(
                videoId: (string) $record->id,
                sourceKey: $record->source_key,
                mode: $validated['mode'],
                // Detection only; the mark is chosen at apply.
                markType: JobRequest::MARK_NONE,
                profile: $validated['profile'] ?? 'balanced',
            ));
        } catch (\InvalidArgumentException $e) {
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

        $this->log()->info('clearcut.start', ['kind' => 'analyse', 'job_row' => $record->id, 'recording' => $recording->getKey(), 'user' => $request->user()?->getAuthIdentifier()]);
        dispatch(WatchClearcutJob::forRow($record->id));

        return response()->json(['id' => $record->id] + $status->toArray(), 202);
    }

    /** Detect and encode in one step, without review. */
    public function process(Request $request, string $recording): JsonResponse
    {
        $validated = $request->validate([
            'mode' => 'required|in:none,fixed,auto,ai',
            'profile' => 'nullable|in:fast,balanced,thorough',
        ] + self::ENCODE_RULES);

        $recording = $this->files->findRecording($recording);
        if ($refused = $this->refuseStart(collect([$recording]), $validated['mark_type'] ?? null)) {
            return $refused;
        }

        try {
            $record = $this->newRecord($request, $recording, self::settingsFrom($validated, review: false));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        try {
            $status = $this->clearcut->process(new JobRequest(
                videoId: (string) $record->id,
                sourceKey: $record->source_key,
                mode: $validated['mode'],
                redactionStyle: $validated['redaction_style'] ?? JobRequest::STYLE_BLUR,
                brand: $validated['brand'] ?? '',
                markType: $validated['mark_type'] ?? JobRequest::MARK_NONE,
                profile: $validated['profile'] ?? 'balanced',
                outputDestination: $validated['output_destination'] ?? null,
                markSize: $validated['mark_size'] ?? JobRequest::SIZE_LARGE,
                markSpeed: (float) ($validated['mark_speed'] ?? 1.0),
            ));
        } catch (\InvalidArgumentException $e) {
            $record->delete();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ClearcutRequestException $e) {
            $record->update(['state' => 'failed', 'error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ClearcutUnavailableException $e) {
            $record->delete();

            return response()->json(['message' => $e->getMessage()], 503);
        }

        // Nobody reviewed this one: reviewed_by_human stays false, as in the audit.
        $record->update([
            'service_job_id' => $status->jobId,
            'state' => $status->state,
        ]);

        $this->log()->info('clearcut.start', ['kind' => 'process', 'job_row' => $record->id, 'recording' => $recording->getKey(), 'user' => $request->user()?->getAuthIdentifier()]);
        dispatch(WatchClearcutJob::forRow($record->id));

        return response()->json(['id' => $record->id] + $status->toArray(), 202);
    }

    /**
     * The row a job reports to.
     *
     * @param  array<string, mixed>  $settings
     */
    private function newRecord(Request $request, Model $recording, array $settings, ?string $sourceKey = null): ClearcutJob
    {
        return ClearcutJob::create([
            'source_key' => $sourceKey ?? $this->files->sourceKeyFor($recording),
            'subject_type' => $recording->getMorphClass(),
            'subject_id' => $recording->getKey(),
            'settings' => $settings,
            'requested_by' => $request->user()?->getAuthIdentifier(),
        ]);
    }

    /**
     * Why these recordings cannot be started, or null. The whole start is
     * refused if any fails; otherwise their older pending reviews are
     * superseded, so a recording never has two.
     *
     * @param  Collection<int, Model>  $recordings
     */
    private function refuseStart(Collection $recordings, ?string $markType): ?JsonResponse
    {
        if (! config('clearcut.enabled', true)) {
            return response()->json(['message' => 'Video processing is switched off (CLEARCUT_ENABLED).'], 422);
        }

        $problems = [];
        $busy = false;

        foreach ($recordings as $recording) {
            $reason = $this->refusalFor($recording, $markType);

            if ($reason === null && $this->rowsOf(collect([$recording]))
                ->whereIn('state', [JobStatus::QUEUED, JobStatus::RUNNING])
                ->exists()) {
                $reason = 'is already being processed';
                $busy = true;
            }

            if ($reason !== null) {
                $problems[] = ['id' => $recording->getKey(), 'reason' => $reason];
            }
        }

        if ($problems !== []) {
            $message = collect($problems)->map(fn ($p) => "Recording {$p['id']} {$p['reason']}")->implode('; ');
            $this->log()->info('clearcut.start_refused', ['recordings' => $recordings->map(fn (Model $r) => $r->getKey())->all(), 'problems' => count($problems)]);

            // 409 when waiting would help, 422 when the selection is wrong.
            return response()->json(['message' => $message.'.', 'problems' => $problems], $busy && count($problems) === 1 ? 409 : 422);
        }

        $superseded = $this->rowsOf($recordings)
            ->where('state', JobStatus::DONE)
            ->whereNull('service_job_id')
            ->whereNotNull('settings')
            ->get();
        foreach ($superseded as $row) {
            try {
                $this->clearcut->discardProposal((string) $row->service_analysis_id);
            } catch (ClearcutRequestException|ClearcutUnavailableException) {
                // Proposals expire on their own.
            }
            $row->update(['state' => JobStatus::CANCELLED, 'error' => 'Superseded by a newer run', 'finished_at' => now()]);
        }
        if ($superseded->isNotEmpty()) {
            $this->log()->info('clearcut.review_superseded', ['job_rows' => $superseded->pluck('id')->all()]);
        }

        return null;
    }

    /**
     * The run's choices as sent, for resuming it. Missing keys stay missing.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private static function settingsFrom(array $validated, bool $review): array
    {
        return array_intersect_key($validated, array_flip([
            'mode', 'profile', 'redaction_style', 'brand', 'mark_type',
            'mark_size', 'mark_speed', 'output_destination',
        ])) + ['review' => $review];
    }

    /** Start several recordings under one set of settings; one row per recording. */
    public function startBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Recording ids, never object keys: see analyze().
            'video_ids' => 'required|array|min:1|max:'.BatchRequest::MAX_RECORDINGS,
            'video_ids.*' => 'required|distinct',
            'kind' => 'required|in:analyse,process',
            'mode' => 'required|in:none,fixed,auto,ai',
            'profile' => 'nullable|in:fast,balanced,thorough',
        ] + self::ENCODE_RULES);

        $analyse = $validated['kind'] === 'analyse';
        if ($analyse && $validated['mode'] === 'none') {
            return response()->json(['message' => 'An analysis needs a detection mode'], 422);
        }

        $recordings = $this->files->model()::findMany($validated['video_ids'])->keyBy(fn (Model $r) => (string) $r->getKey());
        $missing = array_diff(array_map('strval', $validated['video_ids']), $recordings->keys()->all());
        if ($missing !== []) {
            return response()->json(['message' => 'No such recording: '.implode(', ', $missing)], 422);
        }

        if ($refused = $this->refuseStart($recordings->values(), $validated['mark_type'] ?? null)) {
            return $refused;
        }

        // All sources resolved before any row is created.
        try {
            $sources = $recordings->map(fn (Model $r) => $this->files->sourceKeyFor($r));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $settings = self::settingsFrom($validated, review: $analyse);
        $records = [];
        foreach ($validated['video_ids'] as $id) {
            $records[] = $this->newRecord($request, $recordings[(string) $id], $settings, $sources[(string) $id]);
        }

        try {
            $template = new JobRequest(
                // Template only: each recording brings its own id and key.
                videoId: (string) $records[0]->id,
                sourceKey: $records[0]->source_key,
                mode: $validated['mode'],
                redactionStyle: $validated['redaction_style'] ?? JobRequest::STYLE_BLUR,
                brand: $validated['brand'] ?? '',
                // An analysis encodes nothing; apply carries the mark.
                markType: $analyse ? JobRequest::MARK_NONE : ($validated['mark_type'] ?? JobRequest::MARK_NONE),
                profile: $validated['profile'] ?? 'balanced',
                outputDestination: $validated['output_destination'] ?? null,
                markSize: $validated['mark_size'] ?? JobRequest::SIZE_LARGE,
                markSpeed: (float) ($validated['mark_speed'] ?? 1.0),
            );
            $batch = $this->clearcut->processBatch(BatchRequest::of(
                collect($records)->mapWithKeys(fn (ClearcutJob $r) => [(string) $r->id => $r->source_key])->all(),
                $template,
                $analyse ? 'analyse' : 'process',
            ));
        } catch (\InvalidArgumentException $e) {
            ClearcutJob::whereKey(collect($records)->pluck('id'))->delete();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ClearcutRequestException $e) {
            ClearcutJob::whereKey(collect($records)->pluck('id'))
                ->update(['state' => 'failed', 'error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ClearcutUnavailableException $e) {
            ClearcutJob::whereKey(collect($records)->pluck('id'))->delete();

            return response()->json(['message' => $e->getMessage()], 503);
        }

        // Matched by the row id each job carries, not by position.
        $byId = collect($records)->keyBy(fn (ClearcutJob $r) => (string) $r->id);
        foreach ($batch->jobs as $job) {
            $byId->get((string) $job->videoId)?->update([
                $analyse ? 'service_analysis_id' : 'service_job_id' => $job->jobId,
                'batch_id' => $batch->batchId,
                'state' => $job->state,
            ]);
        }

        $this->log()->info('clearcut.start', ['kind' => $analyse ? 'analyse' : 'process', 'batch' => $batch->batchId, 'job_rows' => collect($records)->pluck('id')->all(), 'user' => $request->user()?->getAuthIdentifier()]);
        dispatch(WatchClearcutJob::forBatch($batch->batchId));

        return response()->json($this->batchPayload($batch->toArray()), 202);
    }

    /** The whole batch in one poll, each job under its row's id. */
    public function batchStatus(string $batchId): JsonResponse
    {
        try {
            $batch = $this->clearcut->batch($batchId);
        } catch (ClearcutRequestException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        foreach ($batch->jobs as $job) {
            $row = ClearcutJob::where('service_analysis_id', $job->jobId)
                ->orWhere('service_job_id', $job->jobId)
                ->first();
            if ($row !== null) {
                $row->syncFrom($job);
                $this->files->place($row);
            }
        }

        return response()->json($this->batchPayload($batch->toArray()));
    }

    /** Stop whatever in a batch is still running. */
    public function cancelBatch(string $batchId): JsonResponse
    {
        try {
            $cancelled = $this->clearcut->cancelBatch($batchId);
        } catch (ClearcutRequestException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['cancelled' => $cancelled]);
    }

    /**
     * Each job with its row id and its recording as `video_id`; the service's
     * job id names nothing on the page.
     *
     * @param  array<string, mixed>  $batch
     * @return array<string, mixed>
     */
    private function batchPayload(array $batch): array
    {
        $ids = array_column($batch['jobs'], 'job_id');
        $rows = ClearcutJob::whereIn('service_analysis_id', $ids)
            ->orWhereIn('service_job_id', $ids)
            ->get();

        $batch['jobs'] = array_map(function (array $job) use ($rows) {
            $row = $rows->first(fn (ClearcutJob $r) => in_array($job['job_id'], [$r->service_analysis_id, $r->service_job_id], true));

            return ['id' => $row?->id, 'video_id' => (string) $row?->subject_id] + $job;
        }, $batch['jobs']);

        return $batch;
    }

    /** Poll an analysis or an encode. */
    public function status(ClearcutJob $record): JsonResponse
    {
        $serviceJobId = $record->service_job_id ?? $record->service_analysis_id;

        if ($serviceJobId === null) {
            return response()->json(['message' => 'Nothing has been started yet'], 409);
        }

        try {
            $status = $this->clearcut->job($serviceJobId);
        } catch (ClearcutRequestException $e) {
            // The service forgets finished jobs after an hour; the row keeps the outcome.
            if ($e->isNotFound() && $record->finished_at !== null) {
                return response()->json($record->only([
                    'state', 'stage', 'progress', 'output_key', 'audit_key', 'error',
                ]));
            }

            if ($e->isNotFound()) {
                $record->markLost();

                return response()->json($record->only(['state', 'stage', 'progress', 'error']));
            }

            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $record->syncFrom($status);
        $this->files->place($record);

        // A failed placement turns the row to failed.
        if ($record->state === JobStatus::FAILED && $status->succeeded()) {
            return response()->json(['id' => $record->id] + $record->only(['state', 'stage', 'error']));
        }

        return response()->json($status->toArray());
    }

    /**
     * Per-recording state and the run to resume, for `video_ids`. Rows still
     * running are synced with the service first, so the answer is current
     * even with no watch on the queue.
     */
    public function recordingsStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'video_ids' => 'required|array|min:1|max:500',
            'video_ids.*' => 'required|distinct',
        ]);

        $recordings = $this->files->model()::findMany($validated['video_ids']);
        $rows = $this->rowsOf($recordings)->orderBy('id')->get();

        foreach ($rows->whereIn('state', [JobStatus::QUEUED, JobStatus::RUNNING]) as $row) {
            try {
                $row->syncFrom($this->clearcut->job((string) ($row->service_job_id ?? $row->service_analysis_id)));
            } catch (ClearcutRequestException $e) {
                if ($e->isNotFound()) {
                    $row->markLost();
                }
            } catch (ClearcutUnavailableException) {
                // Left as it is.
            }
        }

        foreach ($rows as $row) {
            $this->files->place($row);
        }

        $files = $recordings->map(fn (Model $recording) => ['id' => $recording->getKey()]
            + self::fileState($rows->where('subject_id', $recording->getKey())))->values();

        return response()->json([
            'enabled' => (bool) config('clearcut.enabled', true),
            'in_progress' => $files->contains(fn ($f) => $f['state'] === 'running'),
            'run' => $this->runToResume($rows),
            'files' => $files,
        ]);
    }

    /** Stop the running work of `video_ids`. Finished jobs stay finished. */
    public function cancelRecordings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'video_ids' => 'required|array|min:1|max:500',
            'video_ids.*' => 'required|distinct',
        ]);

        $recordings = $this->files->model()::findMany($validated['video_ids']);
        $rows = $this->rowsOf($recordings)
            ->whereIn('state', [JobStatus::QUEUED, JobStatus::RUNNING])
            ->get();

        if ($rows->isEmpty()) {
            return response()->json(['message' => 'Nothing is being processed for these recordings.'], 409);
        }

        $stopped = 0;
        $unreachable = 0;
        foreach ($rows as $row) {
            try {
                if ($this->clearcut->cancel((string) ($row->service_job_id ?? $row->service_analysis_id))) {
                    $row->update(['state' => JobStatus::CANCELLED, 'claimed_at' => null, 'finished_at' => now()]);
                    $stopped++;
                }
            } catch (ClearcutRequestException $e) {
                if ($e->isNotFound()) {
                    $row->markLost();
                }
            } catch (ClearcutUnavailableException) {
                $unreachable++;
            }
        }

        $this->log()->info('clearcut.cancel', [
            'rows' => $rows->count(),
            'stopped' => $stopped,
            'unreachable' => $unreachable,
            'user' => $request->user()?->getAuthIdentifier(),
        ]);

        if ($unreachable > 0 && $stopped === 0) {
            return response()->json(['message' => 'The processing service could not be reached. Try again.'], 503);
        }

        return response()->json(['cancelled' => $stopped, 'requested' => $rows->count()]);
    }

    /**
     * The rows of these recordings.
     *
     * @param  Collection<int, Model>  $recordings
     */
    private function rowsOf(Collection $recordings)
    {
        return ClearcutJob::query()
            ->where('subject_type', (new ($this->files->model()))->getMorphClass())
            ->whereIn('subject_id', $recordings->map(fn (Model $r) => $r->getKey()));
    }

    /**
     * One recording's state: work under way, and the last finished encode.
     *
     * @param  Collection<int, ClearcutJob>  $rows
     * @return array<string, mixed>
     */
    private static function fileState(Collection $rows): array
    {
        $active = $rows->filter(fn (ClearcutJob $r) => self::isResumable($r))->last();
        $output = $rows->filter(fn (ClearcutJob $r) => $r->service_job_id !== null && $r->state === JobStatus::DONE)->last();
        $latest = $rows->last();

        return [
            // running | review | done | failed | none
            'state' => match (true) {
                $active !== null => $active->awaitsReview() ? 'review' : 'running',
                $output !== null => 'done',
                $latest?->state === JobStatus::FAILED => 'failed',
                default => 'none',
            },
            'redacted' => $output?->redacted,
            'watermarked' => $output?->watermarked,
            'regions' => $output?->regions,
            'processed_at' => $output?->finished_at,
            'error' => $latest?->state === JobStatus::FAILED ? $latest->error : null,
        ];
    }

    /** Open, with the settings to resume it by. */
    private static function isResumable(ClearcutJob $row): bool
    {
        return $row->settings !== null && $row->isOpen();
    }

    /**
     * The latest run to resume: as a batch while the service still has it,
     * otherwise as a single job.
     *
     * @param  Collection<int, ClearcutJob>  $rows
     * @return array<string, mixed>|null
     */
    private function runToResume(Collection $rows): ?array
    {
        $latest = $rows->filter(fn (ClearcutJob $r) => self::isResumable($r))->last();
        if ($latest === null) {
            return null;
        }

        if ($latest->batch_id !== null) {
            $batch = $rows->where('batch_id', $latest->batch_id);

            // A review batch with a recording already sent to encode would
            // offer it for review again; it resumes as a single job instead.
            $resumable = empty($latest->settings['review'])
                || $batch->every(fn (ClearcutJob $r) => $r->service_job_id === null);

            if ($resumable && $this->serviceHasBatch($latest->batch_id)) {
                return [
                    'kind' => 'batch',
                    'id' => $latest->batch_id,
                    'phase' => $batch->contains(
                        fn (ClearcutJob $r) => in_array($r->state, [JobStatus::QUEUED, JobStatus::RUNNING], true)
                    ) ? 'running' : 'review',
                    'settings' => $latest->settings,
                    'video_ids' => $batch->pluck('subject_id')->values(),
                ];
            }
        }

        return [
            'kind' => 'job',
            'id' => (string) $latest->id,
            'phase' => $latest->awaitsReview() ? 'review' : 'running',
            // An encode after review is watched, not reviewed again.
            'settings' => ['review' => $latest->service_job_id === null] + $latest->settings,
            'video_ids' => [$latest->subject_id],
        ];
    }

    private function serviceHasBatch(string $batchId): bool
    {
        try {
            $this->clearcut->batch($batchId);

            return true;
        } catch (ClearcutRequestException|ClearcutUnavailableException) {
            return false;
        }
    }

    /** Ask a running job to stop. Safe at any point: nothing half-written is kept. */
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

        // False: it had already finished.
        return response()->json(['cancelled' => $stopped]);
    }

    /**
     * A URL the player can load: signed and short-lived where the disk can
     * sign (S3, a served local disk), its plain URL otherwise.
     */
    public function videoUrl(string $recording): JsonResponse
    {
        $path = $this->files->pathOf($this->files->findRecording($recording));
        $disk = Storage::disk(config('clearcut.recordings.disk', 'public'));

        $url = $disk->providesTemporaryUrls()
            ? $disk->temporaryUrl($path, now()->addMinutes(30))
            : url($disk->url($path));

        return response()->json(['url' => $url]);
    }

    /** The proposed regions, for the review screen. */
    public function proposal(ClearcutJob $record): JsonResponse
    {
        if ($record->service_analysis_id === null) {
            return response()->json(['message' => 'No analysis was started'], 409);
        }

        try {
            $proposal = $this->clearcut->proposal($record->service_analysis_id);
        } catch (ClearcutRequestException $e) {
            // A proposal expires after a week.
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'job_id' => $proposal->jobId,
            'frame' => $proposal->frame,
            'duration' => $proposal->duration,
            'counts' => $proposal->counts(),
            'fully_reviewed' => $proposal->fullyReviewed(),
            'regions' => array_map(self::regionPayload(...), $proposal->regions),
            // Regions the service could not resolve: left uncovered.
            'rejected' => $proposal->rejected,
        ]);
    }

    /** Throw away a proposal. The row stays, marked cancelled. */
    public function discardProposal(ClearcutJob $record): JsonResponse
    {
        if ($record->service_analysis_id === null) {
            return response()->json(['message' => 'No analysis was started'], 409);
        }

        try {
            $this->clearcut->discardProposal($record->service_analysis_id);
        } catch (ClearcutRequestException $e) {
            // Already gone is the outcome asked for.
            if (! $e->isNotFound()) {
                return response()->json(['message' => $e->getMessage()], $e->status);
            }
        } catch (ClearcutUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        $record->update(['state' => 'cancelled', 'claimed_at' => null, 'finished_at' => now()]);

        return response()->json(null, 204);
    }

    /** Record keep/drop verdicts as they are made. */
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

    /** Add a box the reviewer drew. */
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
            // 422 when the box does not fit the frame.
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(self::regionReply($reply), 201);
    }

    /** Reshape or retime one region. */
    public function editRegion(Request $request, ClearcutJob $record): JsonResponse
    {
        // Absent keys are kept; an explicit null time means the whole recording.
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
            // The detector's box, once a reviewer changed it.
            'original' => $region->original,
            // Per-segment boxes of a scrolling field; the box above is their outline.
            'segments' => $region->segments,
        ];
    }

    /** Encode what the reviewer kept. */
    public function apply(Request $request, ClearcutJob $record): JsonResponse
    {
        $validated = $request->validate([
            'redaction_style' => 'required|in:blur,solid,pixelate',
            'brand' => 'nullable|string|max:64',
            'mark_type' => 'required|in:logo,text,none',
            'mark_size' => 'nullable|in:large,medium,small',
            'mark_speed' => 'nullable|numeric|between:0.25,2',
            'allow_undecided' => 'boolean',
            'output_destination' => 'nullable|in:s3,local',
        ]);

        if ($record->service_analysis_id === null) {
            return response()->json(['message' => 'No analysis was started'], 409);
        }

        if (! config('clearcut.enabled', true)) {
            return response()->json(['message' => 'Video processing is switched off (CLEARCUT_ENABLED).'], 422);
        }

        $recording = $this->files->model()::find($record->subject_id);
        if ($recording !== null && ($reason = $this->refusalFor($recording, $validated['mark_type'])) !== null) {
            return response()->json(['message' => "Recording {$recording->getKey()} {$reason}."], 422);
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
                markSpeed: (float) ($validated['mark_speed'] ?? 1.0),
            );
        } catch (ClearcutRequestException $e) {
            // 409: regions still undecided, and would not be covered.
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $record->update([
            'service_job_id' => $status->jobId,
            'state' => $status->state,
            'reviewed_by_human' => true,
            // The reviewer's final choices over those the analysis started with.
            'settings' => array_intersect_key($validated, array_flip([
                'redaction_style', 'brand', 'mark_type', 'mark_size', 'mark_speed', 'output_destination',
            ])) + ['review' => false] + ($record->settings ?? []),
        ]);

        dispatch(WatchClearcutJob::forRow($record->id));

        // The row id, as every route here takes it.
        return response()->json(['id' => $record->id] + $status->toArray(), 202);
    }

    /** Stream a local output from the service to the browser. */
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
            // Size and checksum let the browser confirm a complete save.
            'Content-Length' => (string) $output['size'],
            'X-Content-SHA256' => $output['sha256'],
            'Content-Disposition' => 'attachment; filename="'.$output['filename'].'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** The audit file of a local output. */
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

    /** Delete a local output on the service once the browser saved it. Irreversible. */
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

        return response()->json(['released' => $released]);
    }

    private function log(): \Psr\Log\LoggerInterface
    {
        return Log::channel(config('clearcut.log_channel'));
    }
}
