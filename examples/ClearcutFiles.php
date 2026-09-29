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
 * - Later runs start from `_org`, so nothing is processed twice.
 *
 * The original stays private: it still holds the data the redaction covers.
 */
class ClearcutFiles
{
    private const SUFFIX = '_org';

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
     * @throws RuntimeException when there is nothing to read
     */
    public function sourceKeyFor(Model $recording): string
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

        $original = $this->originalOf($recording);
        if ($original !== null) {
            if (! $this->private()->exists($original)) {
                throw new RuntimeException(
                    'The original of this recording was deleted, so it cannot be processed again.'
                );
            }

            return $original;
        }

        $path = $this->pathOf($recording);
        $key = self::orgPath($path);
        if ($this->private()->exists($key)) {
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

        return $key;
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
     * recording's `_org` copy: a row from before replacement was switched on
     * read the recording itself, and placing its output now would replace the
     * recording with a stale result.
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
            || $row->source_key === ($this->originalOf($recording) ?? self::orgPath($this->pathOf($recording)));
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
        $passes = ($redacted ? 'r' : '').($watermarked ? 'w' : '');
        if ($passes === '') {
            throw new RuntimeException('its audit records neither a redaction nor a watermark');
        }

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

        DB::transaction(function () use ($recording, $row, $dest, $destPath, $orgKey, $redacted, $watermarked) {
            $attributes = [
                config('clearcut.recordings.path', 'path') => $dest,
                config('clearcut.replace_original.original_path', 'original_path') => $orgKey,
            ];
            if ($size = config('clearcut.replace_original.size')) {
                $attributes[$size] = filesize($destPath);
            }
            $recording->forceFill($attributes)->save();

            $row->update([
                'redacted' => $row->redacted ?? $redacted,
                'watermarked' => $row->watermarked ?? $watermarked,
            ]);
        });

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
