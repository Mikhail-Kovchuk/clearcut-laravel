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
 * Every route is behind authentication AND a permission: `auth:sanctum` and
 * `can:process-recordings` here, the second of which denies until the
 * application defines that ability — so a copy of this file fails closed, with
 * a 403, rather than open. In an application with its own guard and
 * permission middleware (JWT and `permission:`, say), use those instead.
 *
 * That is not the whole check. An id in a URL is a claim, not a fact: this
 * controller does not verify that the user may see THAT recording, and an
 * application where not everyone may see every recording must add it — a
 * policy on the recording, checked in each action — or any permitted user
 * reaches anyone's recording by changing a number.
 */

use App\Http\Controllers\RecordingReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'can:process-recordings'])
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

        // Several recordings under one set of settings. Polled as one batch,
        // so the dialog draws one bar with a segment per recording and
        // "cancel the rest" is one request. Each job in it comes back under
        // its row's id, the id every other route here takes. Declared before
        // the wildcards below, or "batches" would be read as a recording.
        Route::post('batches', [RecordingReviewController::class, 'startBatch']);
        Route::get('batches/{batchId}', [RecordingReviewController::class, 'batchStatus']);
        Route::delete('batches/{batchId}', [RecordingReviewController::class, 'cancelBatch']);

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

            // A box the reviewer drew, and changes to any box's shape or time.
            // The region's name goes in the body: detector names carry spaces
            // and colons.
            Route::post('regions', [RecordingReviewController::class, 'addRegion']);
            Route::patch('regions', [RecordingReviewController::class, 'editRegion']);

            // A local output (output_destination "local"): nothing of it is in
            // S3. The video and its audit are streamed from the service, and
            // released there once the browser has saved them.
            Route::get('output', [RecordingReviewController::class, 'output']);
            Route::get('output/audit', [RecordingReviewController::class, 'outputAudit']);
            Route::delete('output', [RecordingReviewController::class, 'releaseOutput']);

            // Encode what was kept. Refused with 409 while regions are still
            // undecided, because those would not be covered.
            Route::post('apply', [RecordingReviewController::class, 'apply']);
        });
    });
