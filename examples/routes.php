<?php

/**
 * EXAMPLE — add these to routes/api.php and adapt.
 *
 * Laravel 12 ships without routes/api.php; run `php artisan install:api` to
 * create it, or add these to whichever file already carries your API routes.
 *
 * The paths match what `examples/createClearcutApi.ts` in the React package
 * calls. Change one and change the other, or the adapter will 404 against a
 * working backend and look like the service is down.
 *
 * Every route is behind authentication, and the controller re-checks that this
 * user may touch this recording. An id in a URL is a claim, not a fact: without
 * that check any authenticated user reaches anyone's recording by changing a
 * number.
 */

use App\Http\Controllers\RecordingReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])
    ->prefix('recordings')
    ->group(function () {
        // What this deployment offers — brands and profiles, from the service.
        // Served here rather than hardcoded in the panel, so a brand added to
        // the service's config reaches the UI with no frontend release.
        Route::get('options', [RecordingReviewController::class, 'options']);

        // The mark itself, so the settings form can show what will be
        // burned in. Proxied because the browser cannot reach the service.
        Route::get('brands/{slug}/preview', [RecordingReviewController::class, 'brandPreview']);

        // Start detection. Returns a job to poll; the work takes minutes, so
        // nothing here holds a request open waiting for it.
        Route::post('{recording}/analyze', [RecordingReviewController::class, 'analyze']);

        // A short-lived signed URL for the player. NOT a permanent link: the
        // review screen holds it for as long as the tab is open, and it points
        // at a recording full of personal data.
        Route::get('{recording}/url', [RecordingReviewController::class, 'videoUrl']);

        Route::prefix('jobs/{record}')->group(function () {
            Route::get('/', [RecordingReviewController::class, 'status']);
            Route::delete('/', [RecordingReviewController::class, 'cancel']);

            // The proposed regions, and the reviewer's verdicts.
            Route::get('proposal', [RecordingReviewController::class, 'proposal']);

            // Saved as they are made, not only at the end: a reviewer working
            // through thirty regions should not lose the first twenty by
            // closing the tab.
            Route::patch('decisions', [RecordingReviewController::class, 'decide']);

            // Encode what was kept. Refused with 409 while regions are still
            // undecided, because those would not be covered.
            Route::post('apply', [RecordingReviewController::class, 'apply']);
        });
    });
