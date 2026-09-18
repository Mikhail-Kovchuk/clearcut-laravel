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
];
