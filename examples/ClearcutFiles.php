<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ClearcutJob;
use Clearcut\Video\Data\JobStatus;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * EXAMPLE — published into app/Services/ by clearcut:install.
 *
 * Which file the service reads for a recording and, with
 * `clearcut.replace_original.enabled`, putting a finished encode in place of
 * the recording. Everything application-specific comes from config/clearcut.php.
 *
 * With replacement on:
 * - First run: `name.mp4` is copied to `name_org.mp4` on the private disk; the
 *   service reads only that copy.
 * - Finished encode: verified against its audit, moved beside the recording as
 *   `name_r|w|rw.mp4`; `path` points at it, the public original is deleted.
 * - A later run starts from `_org` by default. Asked to continue from the
 *   processed video instead, it reads a private copy of it (`name_src.mp4`),
 *   and its result takes the passes of both: `_r` plus a watermark is `_rw`.
 *
 * The original stays private: it still holds the data the redaction covers.
 * There is one processed video per recording; each placement replaces it.
 */
class ClearcutFiles
{
    private const SUFFIX = '_org';

    /** The private copy of the processed video a continued run reads. */
    private const CONTINUED = '_src';

    public const SOURCE_ORIGINAL = 'original';

    public const SOURCE_PROCESSED = 'processed';

    public function replaces(): bool
    {
        return (bool) config('clearcut.replace_original.enabled', false);
    }

    /** @return class-string<Model> */
    public function model(): string
    {
        return config('clearcut.recordings.model');
    }

    public function findRecording(int|string $id): Model
    {
        return $this->model()::findOrFail($id);
    }

    /**
     * The key the service reads from. With replacement on, makes the `_org`
     * copy on first use.
     *
     * @param  string  $source  `processed` continues from the video now at
     *                          `path`; a recording never processed has none,
     *                          and starts from its original either way.
     *
     * @throws RuntimeException when there is nothing to read
     */
    public function sourceKeyFor(Model $recording, string $source = self::SOURCE_ORIGINAL): string
    {
        if (! $this->replaces()) {
            $key = (string) $recording->getAttribute(
                config('clearcut.recordings.source_key') ?? config('clearcut.recordings.path', 'path')
            );
            if ($key === '') {
                throw new RuntimeException('The recording has no file to process.');
            }

            return $key;
        }

        if ($source === self::SOURCE_PROCESSED && $this->isProcessed($recording)) {
            return $this->copyProcessed($recording);
        }

        $original = $this->originalOf($recording);
        if ($original !== null) {
            if (! $this->private()->exists($original)) {
                throw new RuntimeException(
                    'The original of this recording was deleted, so it cannot be processed again.'
                );
            }
            $this->shareWithService($original);

            return $original;
        }

        $path = $this->pathOf($recording);
        $key = self::orgPath($path);
        if ($this->private()->exists($key)) {
            $this->shareWithService($key);

            return $key;
        }

        $source = $this->public()->path($path);
        if (! is_file($source)) {
            throw new RuntimeException('The recording file is missing from storage.');
        }

        $target = $this->private()->path($key);
        $temp = $target.'.part-'.bin2hex(random_bytes(4));
        $started = microtime(true);

        if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0775, true) && ! is_dir(dirname($target))) {
            throw new RuntimeException('Could not create the private folder for the original.');
        }

        try {
            // Copy, not move: the public file stays until an encode succeeds.
            if (! copy($source, $temp) || filesize($temp) !== filesize($source)) {
                throw new RuntimeException('Could not copy the recording to private storage.');
            }
            rename($temp, $target);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }

        $this->log()->info('clearcut.original_copied', [
            'recording' => $recording->getKey(),
            'key' => $key,
            'bytes' => filesize($target),
            'elapsed' => round(microtime(true) - $started, 1),
        ]);

        $this->shareWithService($key);

        return $key;
    }

    /**
     * A private copy of the processed video, for the service to read: it
     * reads only the private disk. Made afresh for every run, over the last
     * one — the processed video may have changed since.
     */
    private function copyProcessed(Model $recording): string
    {
        $path = $this->pathOf($recording);
        $source = $this->public()->path($path);
        if (! is_file($source)) {
            throw new RuntimeException('The processed video is missing from storage.');
        }

        $key = (string) $this->continuedKeyFor($recording);
        $target = $this->private()->path($key);
        $temp = $target.'.part-'.bin2hex(random_bytes(4));
        $started = microtime(true);

        try {
            if (! copy($source, $temp) || filesize($temp) !== filesize($source)) {
                throw new RuntimeException('Could not copy the processed video to private storage.');
            }
            rename($temp, $target);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }

        $this->log()->info('clearcut.processed_copied', [
            'recording' => $recording->getKey(),
            'key' => $key,
            'from' => $path,
            'bytes' => filesize($target),
            'elapsed' => round(microtime(true) - $started, 1),
        ]);

        $this->shareWithService($key);

        return $key;
    }

    /** Whether `path` holds a processed video rather than the recording itself. */
    public function isProcessed(Model $recording): bool
    {
        return $this->replaces() && $this->passesOf($recording) !== '';
    }

    /**
     * The passes in the processed video's name: `rw` for `name_rw.mp4`, '' for
     * a recording not processed.
     */
    public function passesOf(Model $recording): string
    {
        $original = $this->originalOf($recording);
        if ($original === null) {
            return '';
        }

        $base = preg_replace('/'.preg_quote(self::SUFFIX, '/').'$/', '', pathinfo($original, PATHINFO_FILENAME));
        $name = pathinfo($this->pathOf($recording), PATHINFO_FILENAME);

        return preg_match('/^'.preg_quote((string) $base, '/').'_([rwt]+)$/', $name, $m) === 1 ? $m[1] : '';
    }

    /**
     * The copy a continued run read (`dir/name_src.mp4`), where one may exist.
     * Left behind by a run that failed or was stopped; deleted wherever the
     * original is.
     */
    public function continuedKeyFor(Model $recording): ?string
    {
        $original = $this->originalOf($recording);
        if ($original === null) {
            return null;
        }

        $info = pathinfo($original);
        $dir = $info['dirname'] !== '.' ? $info['dirname'].'/' : '';
        $base = preg_replace('/'.preg_quote(self::SUFFIX, '/').'$/', '', $info['filename']);

        return $dir.$base.self::CONTINUED.(isset($info['extension']) ? '.'.$info['extension'] : '');
    }

    /** Whether the row read the copy of the recording's processed video. */
    private function continues(ClearcutJob $row, Model $recording): bool
    {
        $key = $this->continuedKeyFor($recording);

        return $key !== null && $row->source_key === $key;
    }

    /**
     * Group read on the original and group entry to every folder above it, up
     * to the disk root. Laravel makes private folders 0700, and a service on
     * this machine reads as a member of the web server's group; others get
     * nothing.
     */
    private function shareWithService(string $key): void
    {
        $root = rtrim($this->private()->path(''), '/\\');
        $path = $this->private()->path($key);

        $shared = @chmod($path, (fileperms($path) & 0777) | 0040);
        for ($dir = dirname($path); strlen($dir) > strlen($root); $dir = dirname($dir)) {
            $shared = @chmod($dir, (fileperms($dir) & 0777) | 0050) && $shared;
        }

        if (! $shared) {
            $this->log()->warning('clearcut.original_unshared', ['key' => $key]);
        }
    }

    /**
     * Put a finished encode in place of the recording. Idempotent: claimed via
     * `placed_at`, so concurrent callers cannot place it twice.
     */
    public function place(ClearcutJob $row): void
    {
        if (! $this->placeable($row)) {
            return;
        }

        $claimed = ClearcutJob::query()
            ->whereKey($row->id)
            ->whereNull('placed_at')
            ->update(['placed_at' => now()]) === 1;
        if (! $claimed) {
            return;
        }

        $this->log()->info('clearcut.place_start', ['job_row' => $row->id, 'recording' => $row->subject_id]);

        try {
            $placed = $this->doPlace($row);
            $this->log()->info('clearcut.place_finish', ['job_row' => $row->id, 'recording' => $row->subject_id, 'path' => $placed]);
        } catch (\Throwable $e) {
            // The recording is untouched: nothing public changes before verification.
            $row->update([
                'state' => JobStatus::FAILED,
                'stage' => 'failed',
                'error' => 'The encode finished but could not be put in place: '.$e->getMessage(),
            ]);
            $this->log()->error('clearcut.place_finish', [
                'job_row' => $row->id,
                'recording' => $row->subject_id,
                'state' => 'failed',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A finished encode waiting to be put in place. Only one made from this
     * recording's `_org` copy or from the copy of its processed video: a row
     * from before replacement was switched on read the recording itself, and
     * placing its output now would replace the recording with a stale result.
     */
    public function placeable(ClearcutJob $row): bool
    {
        if (! $this->replaces()
            || $row->state !== JobStatus::DONE
            || $row->service_job_id === null
            || $row->output_key === null   // local output: nothing in storage
            || $row->placed_at !== null
            || $row->subject_type !== (new ($this->model()))->getMorphClass()) {
            return false;
        }

        // A recording that is gone still fails the row, in doPlace().
        $recording = $this->model()::find($row->subject_id);

        return $recording === null
            || $row->source_key === ($this->originalOf($recording) ?? self::orgPath($this->pathOf($recording)))
            || $this->continues($row, $recording);
    }

    private function doPlace(ClearcutJob $row): string
    {
        $recording = $this->model()::find($row->subject_id)
            ?? throw new RuntimeException('the recording no longer exists');

        $audit = json_decode((string) $this->private()->get((string) $row->audit_key), true);
        if (! is_array($audit)) {
            throw new RuntimeException('its audit file could not be read');
        }

        // From the audit: what was done, not what was asked.
        $redacted = ($audit['mode'] ?? 'none') !== 'none';
        $watermarked = ($audit['mark_type'] ?? 'none') !== 'none';
        $trimmed = ! empty($audit['cuts']);
        if (! $redacted && ! $watermarked && ! $trimmed) {
            throw new RuntimeException('its audit records neither a redaction, a watermark nor a cut');
        }

        // Continued from the processed video: the result carries what that
        // video already had, and must have been made from the one now in
        // place — a run placed meanwhile would otherwise be silently undone.
        $continued = $this->continues($row, $recording);
        if ($continued) {
            $current = $this->public()->path($this->pathOf($recording));
            if (! is_file($current)
                || ! hash_equals((string) ($audit['source_sha256'] ?? ''), hash_file('sha256', $current))) {
                throw new RuntimeException('the processed video changed after this run started from it; start it again');
            }
            $before = $this->passesOf($recording);
            $redacted = $redacted || str_contains($before, 'r');
            $watermarked = $watermarked || str_contains($before, 'w');
            $trimmed = $trimmed || str_contains($before, 't');
        }
        $passes = ($redacted ? 'r' : '').($watermarked ? 'w' : '').($trimmed ? 't' : '');

        $output = $this->private()->path($row->output_key);
        if (! is_file($output) || filesize($output) === 0) {
            throw new RuntimeException('the output file is missing or empty');
        }
        if (! hash_equals((string) ($audit['output_sha256'] ?? ''), hash_file('sha256', $output))) {
            throw new RuntimeException('the output does not match the checksum in its audit');
        }

        $oldPath = $this->pathOf($recording);
        $orgKey = $this->originalOf($recording) ?? self::orgPath($oldPath);
        $org = $this->private()->path($orgKey);
        if (! is_file($org)) {
            throw new RuntimeException('the private original is missing');
        }

        $firstPlacement = $this->originalOf($recording) === null;
        if ($firstPlacement && ! hash_equals((string) ($audit['source_sha256'] ?? ''), hash_file('sha256', $org))) {
            // The public original is deleted below; the copy must match it.
            throw new RuntimeException('the private original does not match the file that was processed');
        }

        $info = pathinfo($orgKey);
        $base = preg_replace('/'.preg_quote(self::SUFFIX, '/').'$/', '', $info['filename']);
        $dest = ($info['dirname'] !== '.' ? $info['dirname'].'/' : '').$base.'_'.$passes.'.'.($info['extension'] ?? 'mp4');

        $destPath = $this->public()->path($dest);
        if (is_file($destPath) && $dest !== $oldPath) {
            throw new RuntimeException("{$dest} already exists and is not this recording's processed video");
        }
        if (! is_dir(dirname($destPath)) && ! mkdir(dirname($destPath), 0775, true) && ! is_dir(dirname($destPath))) {
            throw new RuntimeException('the folder for the processed video could not be created');
        }

        // Temp file + rename: readers never see a partial video.
        $temp = $destPath.'.part-'.bin2hex(random_bytes(4));
        if (! @rename($output, $temp) && ! (copy($output, $temp) && unlink($output))) {
            throw new RuntimeException('the output could not be moved to the public disk');
        }
        if (! rename($temp, $destPath)) {
            @rename($temp, $output);
            throw new RuntimeException('the output could not be renamed into place');
        }

        DB::transaction(function () use ($recording, $row, $dest, $destPath, $orgKey, $redacted, $watermarked, $trimmed, $continued) {
            $attributes = [
                config('clearcut.recordings.path', 'path') => $dest,
                config('clearcut.replace_original.original_path', 'original_path') => $orgKey,
            ];
            if ($size = config('clearcut.replace_original.size')) {
                $attributes[$size] = filesize($destPath);
            }
            $recording->forceFill($attributes)->save();

            // A continued run's flags describe the video now in place, not
            // only its own pass: they are what a page's badges show.
            $row->update($continued ? [
                'redacted' => $redacted,
                'watermarked' => $watermarked,
                'trimmed' => $trimmed,
            ] : [
                'redacted' => $row->redacted ?? $redacted,
                'watermarked' => $row->watermarked ?? $watermarked,
                'trimmed' => $row->trimmed ?? $trimmed,
            ]);
        });

        // The copy it read has served its purpose.
        if ($continued && $this->private()->exists((string) $row->source_key)) {
            $this->private()->delete((string) $row->source_key);
            $this->log()->info('clearcut.processed_copy_deleted', ['recording' => $recording->getKey(), 'key' => $row->source_key]);
        }

        // The public original (first run) or the previous output.
        if ($oldPath !== $dest && $this->public()->exists($oldPath)) {
            $this->public()->delete($oldPath);
            $this->log()->info('clearcut.public_file_deleted', [
                'recording' => $recording->getKey(),
                'path' => $oldPath,
                'was' => $firstPlacement ? 'original' : 'previous_output',
            ]);
        }

        return $dest;
    }

    /**
     * Delete the private originals of recordings that are final
     * (`clearcut.replace_original.delete_originals`). Recordings with work in
     * progress keep theirs; `original_path` is kept, so a later run is refused.
     *
     * @param  iterable<Model>  $recordings
     */
    public function deleteOriginals(iterable $recordings): int
    {
        if (! $this->replaces() || ! config('clearcut.replace_original.delete_originals')) {
            return 0;
        }

        $deleted = 0;
        foreach ($recordings as $recording) {
            $busy = ClearcutJob::query()
                ->where('subject_type', $recording->getMorphClass())
                ->where('subject_id', $recording->getKey())
                ->where(fn ($q) => $q
                    ->whereIn('state', [JobStatus::QUEUED, JobStatus::RUNNING])
                    ->orWhere(fn ($q) => $q->where('state', JobStatus::DONE)->whereNull('service_job_id')))
                ->exists();
            if ($busy) {
                $this->log()->warning('clearcut.original_kept', ['recording' => $recording->getKey(), 'reason' => 'work_in_progress']);

                continue;
            }

            $continued = $this->continuedKeyFor($recording);
            if ($continued !== null && $this->private()->exists($continued)) {
                $this->private()->delete($continued);
            }

            $key = $this->originalOf($recording) ?? self::orgPath($this->pathOf($recording));
            if ($this->private()->exists($key)) {
                $this->private()->delete($key);
                $deleted++;
                $this->log()->info('clearcut.original_deleted', [
                    'recording' => $recording->getKey(),
                    'key' => $key,
                    'processed' => $this->originalOf($recording) !== null,
                ]);
            }
        }

        return $deleted;
    }

    /**
     * Delete `*.part-xxxx` files left by interrupted copies and moves, in the
     * folders of these recordings. Younger than an hour may still be in use.
     *
     * @param  iterable<Model>  $recordings
     * @return list<string> the files found, as `disk:path`
     */
    public function cleanTemp(iterable $recordings, bool $dryRun = false): array
    {
        if (! $this->replaces()) {
            return [];
        }

        $dirs = [];
        foreach ($recordings as $recording) {
            foreach ([$this->pathOf($recording), $this->originalOf($recording)] as $path) {
                if ($path !== null && $path !== '') {
                    $dirs[dirname($path)] = true;
                }
            }
        }

        $cutoff = time() - 3600;
        $found = [];
        foreach (['public' => $this->public(), 'private' => $this->private()] as $name => $disk) {
            foreach (array_keys($dirs) as $dir) {
                foreach ($disk->files($dir === '.' ? '' : $dir) as $path) {
                    if (preg_match('/\.part-[0-9a-f]{8}$/', $path) && $disk->lastModified($path) < $cutoff) {
                        $found[] = "{$name}:{$path}";
                        if (! $dryRun) {
                            $disk->delete($path);
                        }
                    }
                }
            }
        }

        if (! $dryRun && $found !== []) {
            $this->log()->info('clearcut.temp_cleaned', ['files' => count($found)]);
        }

        return $found;
    }

    /** `dir/name.mp4` → `dir/name_org.mp4`. */
    public static function orgPath(string $path): string
    {
        $info = pathinfo($path);
        $dir = $info['dirname'] !== '.' ? $info['dirname'].'/' : '';

        return $dir.$info['filename'].self::SUFFIX.(isset($info['extension']) ? '.'.$info['extension'] : '');
    }

    /** The playable file: the recording, or its processed video once placed. */
    public function pathOf(Model $recording): string
    {
        return (string) $recording->getAttribute(config('clearcut.recordings.path', 'path'));
    }

    public function originalOf(Model $recording): ?string
    {
        $original = $recording->getAttribute(config('clearcut.replace_original.original_path', 'original_path'));

        return $original === null || $original === '' ? null : (string) $original;
    }

    private function public(): Filesystem
    {
        return Storage::disk(config('clearcut.recordings.disk', 'public'));
    }

    private function private(): Filesystem
    {
        return Storage::disk(config('clearcut.replace_original.private_disk', 'local'));
    }

    private function log(): \Psr\Log\LoggerInterface
    {
        return Log::channel(config('clearcut.log_channel'));
    }
}
