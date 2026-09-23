<?php

declare(strict_types=1);

namespace Clearcut\Video;

use Clearcut\Video\Data\AnalysisProposal;
use Clearcut\Video\Data\BatchRequest;
use Clearcut\Video\Data\BatchStatus;
use Clearcut\Video\Data\Brand;
use Clearcut\Video\Data\DetectionProfile;
use Clearcut\Video\Data\JobRequest;
use Clearcut\Video\Data\JobStatus;
use Clearcut\Video\Data\ProposedRegion;
use Clearcut\Video\Exceptions\ClearcutException;
use Clearcut\Video\Exceptions\ClearcutRequestException;
use Clearcut\Video\Exceptions\ClearcutUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * HTTP client for a clearcut-video service.
 *
 * Deliberately knows nothing about the application using it: no models, no
 * tables, no audit log, no permission names. Everything specific to a project
 * — claims, queue jobs, authorisation, audit trail — is bound to that
 * project's own schema and belongs in its code, not here.
 *
 * What this DOES own is the service's contract: the routes, the payload
 * shapes, and turning the failures into exceptions a caller can act on.
 *
 * Processing takes minutes, so every job route returns immediately and is
 * polled. The service is a stateless worker: the authoritative record of what
 * was requested lives in the calling application, not in it.
 */
class ClearcutClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout = 30,
        private readonly int $retries = 2,
    ) {
    }

    /**
     * Whether the service is reachable, and which host binaries it resolved.
     *
     * Never throws: a health check that throws cannot be used in the place a
     * health check is wanted. `reachable` is false when it could not be
     * contacted at all.
     *
     * @return array{reachable: bool, status?: string, binaries?: array<string, bool>, error?: string}
     */
    public function health(): array
    {
        try {
            $response = $this->http
                ->timeout(5)
                ->get($this->url('/health'));

            if ($response->failed()) {
                return ['reachable' => false, 'error' => "HTTP {$response->status()}"];
            }

            return ['reachable' => true, ...$response->json()];
        } catch (ConnectionException $e) {
            return ['reachable' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Queue a job that detects and encodes in one pass.
     */
    public function process(JobRequest $request): JobStatus
    {
        return JobStatus::fromArray(
            $this->send('POST', '/jobs', $request->toArray())
        );
    }

    /**
     * Queue a job that only detects, for a human to review.
     *
     * The regions come back through `proposal()` once this job completes —
     * detection takes minutes, so a reviewer starts this and comes back.
     */
    public function analyze(JobRequest $request): JobStatus
    {
        return JobStatus::fromArray(
            $this->send('POST', '/analyze', $request->toArray())
        );
    }

    /**
     * Queue up to five recordings under one settings choice.
     *
     * A batch is a grouping, not a transaction: each recording succeeds or
     * fails on its own. Handle a partial failure by reporting which recordings
     * need redoing, not by discarding the ones that worked — those are finished
     * encodes, and minutes of work each.
     */
    public function processBatch(BatchRequest $request): BatchStatus
    {
        return BatchStatus::fromArray(
            $this->send('POST', '/batches', $request->toArray())
        );
    }

    /**
     * The whole batch's state in one request.
     *
     * One call rather than one per job: five jobs polled separately are five
     * round trips for a single progress bar, and they are then read at slightly
     * different moments — which shows up as a total that moves backwards.
     */
    public function batch(string $batchId): BatchStatus
    {
        return BatchStatus::fromArray(
            $this->send('GET', "/batches/{$batchId}")
        );
    }

    /**
     * Stop whatever in a batch is still running.
     *
     * Returns how many were actually stopped. A batch where four of five had
     * already finished is not an error — the count is how a caller tells that
     * apart from a batch where nothing was cancellable.
     */
    public function cancelBatch(string $batchId): int
    {
        $body = $this->send('DELETE', "/batches/{$batchId}");

        return (int) ($body['cancelled'] ?? 0);
    }

    /**
     * Current state of a job. This is what a poller calls.
     */
    public function job(string $jobId): JobStatus
    {
        return JobStatus::fromArray(
            $this->send('GET', "/jobs/{$jobId}")
        );
    }

    /**
     * Ask for a job to stop.
     *
     * Returns false when it had already finished — not an error, just a race
     * between the cancel and the work. Nothing half-written reaches storage
     * either way: output is verified before it is uploaded.
     */
    public function cancel(string $jobId): bool
    {
        try {
            $this->send('DELETE', "/jobs/{$jobId}");

            return true;
        } catch (ClearcutRequestException $e) {
            if ($e->status === 409) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * The regions an analysis proposed, and the verdicts recorded so far.
     */
    public function proposal(string $jobId): AnalysisProposal
    {
        return AnalysisProposal::fromArray(
            $this->send('GET', "/review/{$jobId}")
        );
    }

    /**
     * Record keep/drop verdicts. Partial updates are fine.
     *
     * Saving as decisions are made, rather than only at the end, means a
     * reviewer working through thirty regions does not lose the first twenty
     * by closing the tab.
     *
     * @param  array<string, string>  $decisions  region name => kept|dropped|undecided
     * @return array{counts: array<string, int>, fully_reviewed: bool}
     */
    public function decide(string $jobId, array $decisions): array
    {
        $body = $this->send('PATCH', "/review/{$jobId}", ['decisions' => $decisions]);

        return [
            'counts' => $body['counts'] ?? [],
            'fully_reviewed' => (bool) ($body['fully_reviewed'] ?? false),
        ];
    }

    /**
     * Add a box the reviewer drew. It is kept from the start.
     *
     * Geometry is in the proposal's frame pixels. A box outside the frame is
     * refused with a 422 rather than trimmed to fit — the screen already keeps
     * a drawn box inside it, so one that is not is a bug worth seeing.
     *
     * The transport retries, so a lost response can add the box twice. That
     * errs towards covering more, never less, and the reviewer sees both.
     *
     * @return array{region: ProposedRegion, counts: array<string, int>, fully_reviewed: bool}
     */
    public function addRegion(
        string $jobId,
        int $x,
        int $y,
        int $w,
        int $h,
        ?float $t0 = null,
        ?float $t1 = null,
    ): array {
        return $this->regionReply($this->send('POST', "/review/{$jobId}/regions", [
            'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 't0' => $t0, 't1' => $t1,
        ]));
    }

    /**
     * Reshape or retime one region. Its decision is left as it was.
     *
     * Only the keys present in `$changes` are sent, and absence is meaningful:
     * a missing `t0` keeps the current start, while `'t0' => null` (with `t1`)
     * means "the whole recording".
     *
     * @param  array{x?: int, y?: int, w?: int, h?: int, t0?: float|null, t1?: float|null}  $changes
     * @return array{region: ProposedRegion, counts: array<string, int>, fully_reviewed: bool}
     */
    public function editRegion(string $jobId, string $name, array $changes): array
    {
        $allowed = array_intersect_key($changes, array_flip(['x', 'y', 'w', 'h', 't0', 't1']));

        return $this->regionReply($this->send(
            'PATCH',
            "/review/{$jobId}/regions",
            ['name' => $name] + $allowed,
        ));
    }

    /**
     * Encode using the regions the reviewer kept.
     *
     * Undecided regions are NOT covered. The service refuses to apply a
     * half-reviewed proposal unless `allowUndecided` is set, because doing so
     * silently exports a video missing the boxes nobody reached — which looks
     * exactly like a correct export.
     */
    public function apply(
        string $jobId,
        string $redactionStyle = 'blur',
        string $brand = '',
        string $markType = 'logo',
        bool $allowUndecided = false,
    ): JobStatus {
        return JobStatus::fromArray(
            $this->send('POST', "/review/{$jobId}/apply", [
                'redaction_style' => $redactionStyle,
                'partner' => $brand,
                'mark_type' => $markType,
                'allow_undecided' => $allowUndecided,
            ])
        );
    }

    /**
     * Throw away a proposal nobody is going to review.
     */
    public function discardProposal(string $jobId): void
    {
        $this->send('DELETE', "/review/{$jobId}");
    }

    /**
     * The detection profiles this deployment offers.
     *
     * Fetched rather than hardcoded so a UI renders whatever the service has:
     * a retuned profile then reaches every client without a release.
     *
     * @return array<int, DetectionProfile>
     */
    public function profiles(): array
    {
        $body = $this->send('GET', '/profiles');

        return array_map(
            DetectionProfile::fromArray(...),
            $body['profiles'] ?? []
        );
    }

    /**
     * The brands this deployment can watermark with.
     *
     * Also fetched rather than hardcoded: which brands exist is the service's
     * configuration, and a client that guesses will name one that is not there.
     *
     * @return array<int, Brand>
     */
    public function brands(): array
    {
        $body = $this->send('GET', '/brands');

        return array_map(Brand::fromArray(...), $body['brands'] ?? []);
    }

    /**
     * The mark a brand would burn in.
     *
     * A logo comes back as raw PNG bytes under `image`; a wordmark as its text.
     * Worth fetching because a brand name alone does not say which asset lands
     * on the footage, and the burn-in cannot be undone.
     *
     * @return array{image: string}|array{mark_type: string, text: string}
     */
    public function brandPreview(string $slug, string $markType = 'logo'): array
    {
        $url = $this->url("/brands/{$slug}/preview?mark_type={$markType}");

        try {
            $response = $this->http
                ->withToken($this->token)
                ->timeout($this->timeout)
                ->get($url);
        } catch (ConnectionException $e) {
            throw new ClearcutUnavailableException(
                "clearcut-video is unreachable at {$this->baseUrl}: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->failed()) {
            throw new ClearcutRequestException(
                $this->describeFailure($response, 'GET', "/brands/{$slug}/preview"),
                $response->status(),
                $response->json() ?? [],
            );
        }

        // Binary for a logo, JSON for a wordmark — decided by what was asked
        // for rather than by sniffing the body.
        return $markType === 'text'
            ? ($response->json() ?? [])
            : ['image' => $response->body()];
    }

    // --- Transport ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $payload = []): array
    {
        try {
            $request = $this->http
                ->withToken($this->token)
                ->timeout($this->timeout)
                // Retries cover a restarting service and transient network
                // faults. Only the transport is retried — a 4xx is a decision,
                // not a blip, and repeating it would just fail again.
                ->retry($this->retries, 250, throw: false);

            $response = match ($method) {
                'GET' => $request->get($this->url($path)),
                'POST' => $request->post($this->url($path), $payload),
                'PATCH' => $request->patch($this->url($path), $payload),
                'DELETE' => $request->delete($this->url($path)),
                default => throw new ClearcutException("Unsupported method {$method}"),
            };
        } catch (ConnectionException $e) {
            // Distinguished from a rejection on purpose: unreachable is worth
            // retrying later, while a rejected request will be rejected again.
            throw new ClearcutUnavailableException(
                "clearcut-video is unreachable at {$this->baseUrl}: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->failed()) {
            throw new ClearcutRequestException(
                $this->describeFailure($response, $method, $path),
                $response->status(),
                $response->json() ?? [],
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Turn a failure into one line naming what actually went wrong.
     *
     * The service's validation errors are nested inside `detail`, and without
     * unwrapping them a caller sees "HTTP 422" with no hint of which field the
     * service objected to.
     */
    private function describeFailure(Response $response, string $method, string $path): string
    {
        $body = $response->json();
        $detail = $body['detail'] ?? null;

        if (is_array($detail)) {
            $messages = array_filter(array_map(
                static fn ($item) => is_array($item) ? ($item['msg'] ?? null) : null,
                $detail,
            ));
            $detail = $messages ? implode('; ', $messages) : json_encode($detail);
        }

        return sprintf(
            '%s %s failed with %d%s',
            $method,
            $path,
            $response->status(),
            $detail ? ": {$detail}" : '',
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{region: ProposedRegion, counts: array<string, int>, fully_reviewed: bool}
     */
    private function regionReply(array $body): array
    {
        return [
            'region' => ProposedRegion::fromArray($body['region'] ?? []),
            'counts' => $body['counts'] ?? [],
            'fully_reviewed' => (bool) ($body['fully_reviewed'] ?? false),
        ];
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
