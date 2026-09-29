<?php

declare(strict_types=1);

return [
    /*
     | Where the clearcut-video service listens.
     |
     | An internal address — a WireGuard peer, a private network host, or a
     | container name. The service must NOT be published to the internet: it
     | holds storage credentials and processes recordings full of personal
     | data, and the only thing in front of it is the shared token below.
     */
    'url' => env('CLEARCUT_URL', 'http://127.0.0.1:8000'),

    /*
     | The shared secret the service expects as a bearer token.
     |
     | Required even on a private network: it is nearly free, and it closes the
     | case where the service ends up reachable and takes orders from anyone.
     */
    'token' => env('CLEARCUT_TOKEN', ''),

    /*
     | Seconds to wait on a single HTTP call.
     |
     | This is NOT how long processing may take — every job route returns
     | immediately and is polled. Thirty seconds is generous for a call that
     | should answer in milliseconds.
     */
    'timeout' => (int) env('CLEARCUT_TIMEOUT', 30),

    /*
     | Transport retries per call.
     |
     | Covers a restarting service and transient network faults. Only the
     | transport is retried: a rejected request is a decision, not a blip, and
     | repeating it would fail the same way.
     */
    'retries' => (int) env('CLEARCUT_RETRIES', 2),

    /*
     | How often a polling job asks for a status update, in seconds.
     |
     | Detection alone runs at roughly 1.5x the recording's duration, so there
     | is nothing to gain from polling faster than this.
     */
    'poll_interval' => (int) env('CLEARCUT_POLL_INTERVAL', 10),

    /*
     | How long a job may run before the caller gives up on it, in seconds.
     |
     | A ceiling, not an expectation. Detection and encoding together run at a
     | few times the recording's length, so an hour covers a long recording
     | with room to spare — and a job still running after that has stopped
     | making progress.
     */
    'max_job_seconds' => (int) env('CLEARCUT_MAX_JOB_SECONDS', 3600),

    /*
     | Off refuses every new run; running ones and their results are untouched.
     */
    'enabled' => (bool) env('CLEARCUT_ENABLED', true),

    /*
     | Seconds between polls of WatchClearcutJob, the queued job that follows
     | a run with the page closed. At least 5.
     */
    'watch_interval' => (int) env('CLEARCUT_WATCH_INTERVAL', 30),

    /*
     | Log channel for the examples' lines; null is the default channel.
     */
    'log_channel' => env('CLEARCUT_LOG_CHANNEL'),

    /*
     | The application's recordings.
     |
     | model: the Eloquent model whose primary key the routes take as
     |        {recording} and `video_ids`.
     | disk:  where the recordings are, for playback.
     | path:  the attribute holding a recording's path on that disk.
     | source_key: the attribute the service reads from, when it is not
     |        `path` (an S3 key, say). Unused with replace_original on.
     */
    'recordings' => [
        'model' => 'App\\Models\\Recording',
        'disk' => env('CLEARCUT_RECORDINGS_DISK', 'public'),
        'path' => 'path',
        'source_key' => null,
    ],

    /*
     | Put the finished video in place of the recording.
     |
     | Off: the output stays where the service wrote it (`output_key`), and the
     | recording is untouched.
     |
     | On: before its first run `name.mp4` is copied to `name_org.mp4` on the
     | private disk, and the service reads only that copy. A finished encode is
     | checked against its audit and moved beside the recording as
     | `name_r.mp4` / `name_w.mp4` / `name_rw.mp4` (redacted, watermarked,
     | both); `path` points at it and the public original is deleted. Later
     | runs start from `_org` again, so nothing is processed twice.
     |
     | Needs local disks, the service on filesystem storage with
     | CLEARCUT_STORAGE_ROOT at the private disk's root, and a nullable string
     | column for `original_path` on the recordings table.
     |
     | delete_originals: ClearcutFiles::deleteOriginals() deletes the `_org`
     | copies, which the application calls when a recording is final (an order
     | closed, say). After that a recording can no longer be processed.
     */
    'replace_original' => [
        'enabled' => (bool) env('CLEARCUT_REPLACE_ORIGINAL', false),
        'private_disk' => env('CLEARCUT_PRIVATE_DISK', 'local'),
        'original_path' => 'original_path',
        // Attribute updated with the new file size; null leaves it alone.
        'size' => null,
        'delete_originals' => (bool) env('CLEARCUT_DELETE_ORIGINALS', false),
    ],
];
