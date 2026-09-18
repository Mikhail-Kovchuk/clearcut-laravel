# clearcut/video-client

Laravel client for a [clearcut-video](../README.md) service — watermarking and
PII redaction for screen recordings.

## What is and is not in here

**In the package** (`src/`): the HTTP client, the data objects, the config and
a service provider. It knows the service's contract and nothing else — no
models, no tables, no audit log, no permission names.

**In `examples/`**: a queued job and a controller, to copy and adapt. They are
examples rather than package code because everything they do beyond calling the
client is bound to one application's schema. A package that guessed at your
claim columns would be harder to use than writing eighty lines yourself.

That split is the whole design. The reusable part is genuinely reusable
because it refuses to know anything about you.

## Install

While this lives inside the service's repository, point composer at the path:

```json
{
    "repositories": [
        { "type": "path", "url": "../clearcut-video/laravel" }
    ],
    "require": {
        "clearcut/video-client": "*"
    }
}
```

Then:

```bash
composer require clearcut/video-client
php artisan vendor:publish --tag=clearcut-config
```

```dotenv
CLEARCUT_URL=http://10.8.0.2:8000
CLEARCUT_TOKEN=the-same-token-the-service-has
```

The service must not be reachable from the internet. It holds storage
credentials and processes recordings full of personal data, and the token is
the only thing in front of it.

## Use

```php
use Clearcut\Video\ClearcutClient;
use Clearcut\Video\Data\JobRequest;

public function __construct(private ClearcutClient $clearcut) {}

$status = $this->clearcut->process(new JobRequest(
    videoId: (string) $recording->id,
    sourceKey: $recording->storage_key,   // a bucket key, not a URL
    mode: JobRequest::MODE_AUTO,
    brand: 'acme',
));

// Minutes, so poll rather than wait.
$status = $this->clearcut->job($status->jobId);
$status->finished();   // done | failed | cancelled
```

### Review

```php
$analysis = $this->clearcut->analyze($request);      // detect only
$proposal = $this->clearcut->proposal($analysis->jobId);

$this->clearcut->decide($analysis->jobId, ['pan-0' => 'kept']);

$encode = $this->clearcut->apply($analysis->jobId, brand: 'acme');
```

**Regions arrive undecided, and only kept ones are covered.** Applying a
half-reviewed proposal is refused with 409 unless `allowUndecided: true` —
otherwise it silently exports a video missing the boxes nobody reached, which
looks exactly like a correct export.

### Discovery

Brands and profiles come from the service, so nothing hardcodes them:

```php
$this->clearcut->brands();     // what this deployment can watermark with
$this->clearcut->profiles();   // speed against thoroughness
$this->clearcut->health();     // reachable? which binaries resolved?
```

`health()` never throws — a health check that throws cannot be used where a
health check is wanted.

## Failures

Two exceptions, separated by the one distinction that changes what to do:

| | Meaning | What to do |
|---|---|---|
| `ClearcutUnavailableException` | could not be reached | release and retry later |
| `ClearcutRequestException` | reached, and rejected the request | fix the request; retrying will fail identically |

`ClearcutRequestException` carries `->status` and `->isValidationError()`,
`->isNotFound()`, `->isConflict()`. A 409 on apply means regions are still
undecided — go back and decide them.

## Two mistakes worth not repeating

Both are in `examples/ProcessRecording.php`, and both have cost real time on
work like this.

**Release the claim on EVERY exit path**, not just success and the failure
handler. An early return that forgets leaves a recording reporting "in
progress" until somebody clears it by hand. That is what `finally` is for —
and `failed()` too, because a terminated process never reaches `finally`.

**A killed process logs nothing.** OOM, a deploy, a worker restart: it never
reaches its error handler. Log a start and a finish line as a pair, so the
diagnostic is the gap where a finish should be. There will be no error line to
find.
