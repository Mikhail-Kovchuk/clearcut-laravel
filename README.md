# clearcut/clearcut-laravel

Laravel client for a clearcut-video service — watermarking and PII redaction
for screen recordings.

> **This repository is a read-only mirror.** It is published from the
> `laravel/` folder of clearcut-video on every release tag, and each sync
> replaces what is here. Changes go to clearcut-video.

## What is and is not in here

**In the package** (`src/`): the HTTP client, the data objects, the config, a
cached brand list (`BrandLabels`) and a service provider. It knows the
service's contract and nothing else — no models, no tables, no audit log, no
permission names.

**In `examples/`**, published by `clearcut:install`: a migration, a model, a
controller and its routes, the file handling (`ClearcutFiles`), a queued job
that follows each run (`WatchClearcutJob`) and two console commands. They are
examples rather than package code because once published they are yours to
change, and a `composer update` must not change them under you.

The migration creates a standalone table with a polymorphic `subject`, so it
attaches to whatever holds recordings without knowing what that is. What the
examples need to know about your recordings — the model, its disk, the
attribute with its path — is in `config/clearcut.php`, under `recordings`.

That split is the whole design. The reusable part is genuinely reusable
because it refuses to know anything about you.

## Install

### Requirements

- PHP 8.2+ and Laravel 11 or 12. Nothing else is installed: the package needs
  only what the framework already ships, Guzzle included.
- A running clearcut-video service this server can reach, and its token. The
  service reads and writes its storage itself — a bucket, or a directory such
  as this application's `public` disk — and this side passes keys, needing no
  S3 driver for it.

### 1. Install

```bash
composer require clearcut/clearcut-laravel
php artisan clearcut:install
```

Or on one line — `&&` in bash, `;` in Windows PowerShell 5.1, which has no `&&`:

```powershell
composer require clearcut/clearcut-laravel; php artisan clearcut:install
```

It takes two commands because composer runs no package's scripts on install,
by design. `clearcut:install` does the rest:

| | |
|---|---|
| `config/clearcut.php` | the config, published |
| `app/Models/ClearcutJob.php` | the model the controller works against |
| `database/migrations/<date>_create_clearcut_jobs_table.php` | the table, dated now |
| `app/Http/Controllers/RecordingReviewController.php` | the controller the React screen calls |
| `routes/clearcut.php` | its routes, loaded from `routes/api.php` with one `require` line |
| `app/Services/ClearcutFiles.php` | which file the service reads; putting a finished video in place |
| `app/Jobs/WatchClearcutJob.php` | follows each run on the queue, so it finishes with the page closed |
| `app/Console/Commands/ClearcutProcess.php`, `ClearcutSync.php` | `clearcut:process` and `clearcut:sync` |
| `.env`, `.env.example` | `CLEARCUT_URL=` and `CLEARCUT_TOKEN=`, empty, where missing |
| `php artisan migrate` | asked first |

Where `routes/api.php` does not exist — Laravel 11 and 12 ship without it — it
offers to run `php artisan install:api`, which creates the file and installs
Sanctum.

**Nothing already there is overwritten**, so running it again is safe: every
file it publishes is application code from then on, and a re-run that replaced
an adapted controller would undo that work without a word. `--force`
overwrites, except the migration, which is never published twice. It ends by
listing what is left to do by hand — steps 2 and 3 below.

The table is where results are remembered. The service keeps jobs in memory
and forgets them on restart, and it never overwrites an original — so this
row is the only record of where the output and its audit file went.

The version is the git tag, shared with the service: the client at `v1.4.0`
speaks the contract of the service at `v1.4.0`. Given no version, composer
writes `^1.0` into `composer.json`, which takes fixes and new endpoints; a
`2.0` means the contract changed and is taken only on purpose.

Do not type the constraint on Windows. `composer` runs through a `.bat` file
there, and cmd treats `^` as an escape and drops it: `:^1.0` arrives as `1.0`,
which pins exactly 1.0.0, and every later `composer update` quietly stays on
it. The only sign is a warning that the constraint "appears too strict".

### 2. The connection

```dotenv
CLEARCUT_URL=http://clearcut.internal:8000
CLEARCUT_TOKEN=the-same-token-the-service-has
```

| Variable | Default | What it is |
|---|---|---|
| `CLEARCUT_URL` | `http://127.0.0.1:8000` | where the service listens |
| `CLEARCUT_TOKEN` | — | the service's `CLEARCUT_AUTH_TOKEN`; required |
| `CLEARCUT_TIMEOUT` | `30` | seconds per HTTP request, not per job |
| `CLEARCUT_RETRIES` | `2` | retries when the service cannot be reached |
| `CLEARCUT_POLL_INTERVAL` | `10` | seconds between polls of a client that waits |
| `CLEARCUT_MAX_JOB_SECONDS` | `3600` | when a watch gives up waiting |
| `CLEARCUT_ENABLED` | `true` | `false` refuses new runs; running ones finish |
| `CLEARCUT_WATCH_INTERVAL` | `30` | seconds between polls of `WatchClearcutJob` |
| `CLEARCUT_LOG_CHANNEL` | — | log channel for the examples; the default channel when empty |
| `CLEARCUT_RECORDINGS_DISK` | `public` | the disk holding the recordings |
| `CLEARCUT_REPLACE_ORIGINAL` | `false` | put finished videos in place of the recordings — see below |
| `CLEARCUT_PRIVATE_DISK` | `local` | where the originals are kept, with replacement on |
| `CLEARCUT_DELETE_ORIGINALS` | `false` | let `ClearcutFiles::deleteOriginals()` delete them |

The service must not be reachable from the internet. It holds storage
credentials and processes recordings full of personal data, and the token is
the only thing in front of it.

Check it from this server:

```bash
php artisan tinker --execute="dump(app(Clearcut\Video\ClearcutClient::class)->health());"
php artisan tinker --execute="dump(app(Clearcut\Video\ClearcutClient::class)->brands());"
```

The first says whether the service is reachable and which binaries it found;
`reachable: false` carries the reason. It does not check the token — `/health`
is open, so a readiness probe needs no credentials. The second does: a wrong
token throws `ClearcutRequestException` with status 401.

### 3. Adapt what was published

The paths in `routes/clearcut.php` match what the React package's adapter
calls, so changing one means changing the other. The controller does not run
until the first two of these are done:

- **The recordings, in `config/clearcut.php`.** `recordings.model` is the
  model whose primary key the routes take as `{recording}` and `video_ids`;
  `recordings.disk` and `recordings.path` say where its file is. The service
  reads the key from that row, never from the request: `recordings.path`, or
  `recordings.source_key` when the service knows the file by another
  attribute. With S3 that is the object key; with the service on filesystem
  storage it is the path relative to `CLEARCUT_STORAGE_ROOT` — for recordings
  on the `public` disk, the path the row already stores, with the root set to
  `storage/app/public`. The service's README, "Storage: a bucket or a
  directory", has the settings.
- **Authentication and permission.** `auth:sanctum` and `can:process-recordings`
  in `routes/clearcut.php` are placeholders. Replace them with your own guard
  and permission middleware. As shipped, `can:` denies until that ability is
  defined, so a copy fails closed with 403 rather than open. Without Sanctum,
  see below: the file is best loaded from somewhere else.
- **Ownership.** A route id is a claim, not a fact. If not every permitted user
  may see every recording, check a policy on the recording in each action, or
  anyone can reach anyone's recording by changing a number.
- **Your own rules.** `refusalFor()` in the controller returns why a recording
  may not be processed — not a video, expired, already carrying a burned-in
  mark — and is checked on every start and on apply. It refuses nothing as
  shipped.
- **`videoUrl()`** signs a 30-minute URL where the disk can (S3, a local disk
  with `serve`), and returns the disk's plain URL otherwise.

#### Without Sanctum

`clearcut:install` loads `routes/clearcut.php` from `routes/api.php` and
guards it with `auth:sanctum`. In an application that authenticates some other
way — JWT, a session guard, its own middleware — that guard does not exist and
every route fails with a 500 before the controller runs.

Load the file from the route group that already protects your admin panel
instead, so it takes that group's authentication, prefix and name:

1. Remove `require __DIR__.'/clearcut.php';` from the end of `routes/api.php`.
2. Add it inside the panel's group — for example in `routes/admin.php`:

   ```php
   Route::group(['middleware' => ['jwt.verify', 'admin']], function () {
       // ...the panel's own routes...

       require __DIR__.'/clearcut.php';
   });
   ```

3. In `routes/clearcut.php`, drop `auth:sanctum`: the group authenticates now.
   Keep a permission if not every user of the panel may process recordings
   (`permission:process_recordings`, in whatever form your application writes
   one), or drop the middleware entirely if they all may:

   ```php
   Route::prefix('recordings')->group(function () {
   ```

4. Check where the routes landed and what guards them:

   ```bash
   php artisan route:list --path=recordings -v
   ```

5. The paths now start with the group's prefix — `admin/recordings/...`
   rather than `api/recordings/...` — so the React adapter is created with it:
   `createClearcutApi('/admin/recordings')`.

### 4. Background work and resuming

Every run the controller starts is followed by `WatchClearcutJob` on the
queue: one poll per attempt, released back to the queue between polls, so it
never holds a worker. The user can close the dialog and leave; the run
finishes, and with replacement on it is put in place. Run a worker
(`php artisan queue:work`, under supervisor or systemd). On the `sync` driver
the watch polls once, and the status routes below settle the rest whenever
they are asked.

The rows are the record of what runs, so any browser can find its way back:

| Route | |
|---|---|
| `GET recordings/status?video_ids[]=…` | per recording: `state` (`running`, `review`, `done`, `failed`, `none`), the last encode's `redacted` / `watermarked` / `regions` / `processed_at`, and `run` — the latest open run with its settings, to reopen the dialog on |
| `POST recordings/cancel` `{video_ids}` | stop the running work of those recordings |

Rows still running are synced with the service on each status request, and a
job the service no longer knows (it restarted) is failed rather than left
running for ever.

From the console, through the same controller actions and checks:

```bash
php artisan clearcut:process --video=12 --video=13 --mode=auto --mark=logo --brand=acme --wait
php artisan clearcut:process --video=12 --review          # detect; review in the panel
php artisan clearcut:sync                                 # settle what a stopped worker left open
php artisan clearcut:sync --minutes=30 --clean-temp       # worth scheduling
```

### 5. Optional: replacing the original

By default the service writes its output beside the original, under its own
`processed/` prefix, and the recording is untouched: `output_key` on the row
says where the result went.

With `CLEARCUT_REPLACE_ORIGINAL=true` the finished video takes the
recording's place instead:

- before its first run `name.mp4` is copied to `name_org.mp4` on the private
  disk, and the service reads only that copy;
- a finished encode is checked against its audit's hashes, moved beside the
  recording as `name_r.mp4`, `name_w.mp4` or `name_rw.mp4` (redacted,
  watermarked, both), `path` is pointed at it, and the public original is
  deleted — only after the private copy has matched the audit's source hash;
- every later run starts from `_org` again, so nothing is redacted twice or
  stamped with a second mark.

The original stays private because it still holds what the redaction covers.
This needs:

- a nullable string column for `replace_original.original_path` on the
  recordings table (`original_path` by default);
- local disks, and the service on filesystem storage with
  `CLEARCUT_STORAGE_ROOT` at the private disk's root (`storage/app/private`
  for Laravel 11+'s `local` disk) and `CLEARCUT_STORAGE_ORIGINALS_PREFIX`
  naming the folder the recordings are in.

`ClearcutFiles::deleteOriginals($recordings)` deletes the `_org` copies when
`CLEARCUT_DELETE_ORIGINALS=true` — call it when a recording is final, from
whatever event means that in your application. A recording with work in
progress keeps its copy. After that it can no longer be processed, and is
refused rather than processed again from its redacted copy.

### By hand

Each piece is also a publish tag, for an application that wants some and not
others:

| Tag | Publishes |
|---|---|
| `clearcut-config` | `config/clearcut.php` |
| `clearcut-models` | `app/Models/ClearcutJob.php` |
| `clearcut-migrations` | `database/migrations/<date>_create_clearcut_jobs_table.php` |
| `clearcut-controllers` | `app/Http/Controllers/RecordingReviewController.php` |
| `clearcut-routes` | `routes/clearcut.php` — then add `require __DIR__.'/clearcut.php';` to `routes/api.php` |
| `clearcut-services` | `app/Services/ClearcutFiles.php` |
| `clearcut-jobs` | `app/Jobs/WatchClearcutJob.php` |
| `clearcut-commands` | `app/Console/Commands/ClearcutProcess.php`, `ClearcutSync.php` |

```bash
php artisan vendor:publish --tag=clearcut-migrations
```

Publish the migration once. `vendor:publish` checks whether the file exists
before it puts today's date in the name, so a second run adds a second copy,
and `migrate` then fails on a table that already exists. `clearcut:install`
guards against that; the tag alone does not.

The date is the moment of publishing where
`database.migrations.update_date_on_publish` is on in `config/database.php` —
as it is in new installs. An older application may not have that key; the file
then keeps a fixed date, which still orders after the framework's own tables.

### Verified

End to end in a clean Laravel 12 install on PHP 8.2, against a real service
and a real S3 bucket: analyse, review, apply, encode. The result landed beside
an untouched original with an audit file recording both hashes.

### Developing the package

Alongside an application, from a checkout of clearcut-video, use a path
repository instead of the mirror — with an explicit version, because a path
has no tag and composer would otherwise call it `dev-main`, which the default
`minimum-stability: stable` refuses:

```json
{ "type": "path", "url": "../clearcut-video/laravel", "options": { "symlink": false, "versions": { "clearcut/clearcut-laravel": "1.99.0" } } }
```

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

### Batches

Up to five recordings under one settings choice. The settings are shared by
construction — one `JobRequest` template and a list of recordings — so "one set
of settings for all" cannot drift into per-video overrides.

```php
use Clearcut\Video\Data\BatchRequest;

$batch = $this->clearcut->processBatch(BatchRequest::of(
    ['12' => 'media/originals/12/source.mp4',
     '13' => 'media/originals/13/source.mp4'],
    new JobRequest(
        videoId: 'unused-by-the-batch',   // each recording carries its own
        sourceKey: 'unused-by-the-batch',
        mode: JobRequest::MODE_AUTO,
        brand: 'acme',
    ),
));

$batch = $this->clearcut->batch($batch->batchId);   // the whole batch, one request
$batch->progress;      // 0..1, weighted by each recording's length
$batch->failures();    // the recordings that need redoing
$batch->successes();   // keep these — they are finished encodes
```

**A batch is a grouping, not a transaction.** Each recording succeeds or fails
on its own, so `failed()` means something in it needs redoing rather than that
nothing was produced. Report the failures; do not discard the successes.

`cancelBatch()` returns how many jobs it actually stopped. Cancellation is
cooperative — a job reaches `cancelled` at its next check, and one already past
that point finishes normally, which is why the count can be lower than the
batch size without anything being wrong.

### Review

```php
$analysis = $this->clearcut->analyze($request);      // detect only
$proposal = $this->clearcut->proposal($analysis->jobId);

$this->clearcut->decide($analysis->jobId, ['pan-0' => 'kept']);

// Correct a box, or draw one detection missed. Both return the region as saved.
$this->clearcut->editRegion($analysis->jobId, 'pan-0', ['w' => 240, 't1' => 12.5]);
$this->clearcut->addRegion($analysis->jobId, x: 40, y: 300, w: 200, h: 36, t0: 4.0, t1: 9.0);

$encode = $this->clearcut->apply($analysis->jobId, brand: 'acme');
```

`editRegion()` sends only the keys it is given: a missing `t0` keeps the
current start, while `'t0' => null, 't1' => null` means the whole recording.
A box that does not fit the frame is refused with 422, never trimmed.

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

For a brand's name on a page that is not about processing, `BrandLabels` keeps
the list for an hour instead of asking each time:

```php
app(Clearcut\Video\BrandLabels::class)->labelFor('acme');   // 'Acme', or 'acme' when unknown
app(Clearcut\Video\BrandLabels::class)->has('acme');
```

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

Both have cost real time on work like this.

**Release the claim on EVERY exit path**, not just success and the failure
handler. An early return that forgets leaves a recording reporting "in
progress" until somebody clears it by hand. That is what `finally` is for —
and `failed()` too, because a terminated process never reaches `finally`.
`clearcut:sync` is the cure for rows that were left open anyway.

**A killed process logs nothing.** OOM, a deploy, a worker restart: it never
reaches its error handler. Log a start and a finish line as a pair, so the
diagnostic is the gap where a finish should be. There will be no error line to
find.
