<?php

/**
 * EXAMPLE — published as routes/clearcut.php by clearcut:install, and loaded
 * from routes/api.php, so the paths sit under /api.
 *
 * The paths match `examples/createClearcutApi.ts` in the React package; change
 * one and change the other.
 *
 * `auth:sanctum` and `can:process-recordings` are placeholders: the second
 * denies until the application defines that ability, so a copy fails closed.
 * With a guard of its own (JWT, say), require this file inside the group that
 * already authenticates the panel and drop both.
 *
 * `{recording}` and `video_ids` are keys of `clearcut.recordings.model`. The
 * controller does not check that the user may see THAT recording; where not
 * everyone may, add a policy check in each action.
 */

use App\Http\Controllers\RecordingReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'can:process-recordings'])
    ->prefix('recordings')
    ->group(function () {
        // Brands, profiles and output settings, from the service.
        Route::get('options', [RecordingReviewController::class, 'options']);
        Route::get('brands/{slug}/preview', [RecordingReviewController::class, 'brandPreview']);

        // Per-recording state and the run to resume (?video_ids[]=…), and
        // stopping their running work. Before the wildcards below.
        Route::get('status', [RecordingReviewController::class, 'recordingsStatus']);
        Route::post('cancel', [RecordingReviewController::class, 'cancelRecordings']);

        // Several recordings under one set of settings, polled as one batch.
        Route::post('batches', [RecordingReviewController::class, 'startBatch']);
        Route::get('batches/{batchId}', [RecordingReviewController::class, 'batchStatus']);
        Route::delete('batches/{batchId}', [RecordingReviewController::class, 'cancelBatch']);

        // Detect for review, or detect and encode in one step. Both return a
        // job to poll.
        Route::post('{recording}/analyze', [RecordingReviewController::class, 'analyze']);
        Route::post('{recording}/process', [RecordingReviewController::class, 'process']);

        Route::get('{recording}/url', [RecordingReviewController::class, 'videoUrl']);

        Route::prefix('jobs/{record}')->group(function () {
            Route::get('/', [RecordingReviewController::class, 'status']);
            Route::delete('/', [RecordingReviewController::class, 'cancel']);

            Route::get('proposal', [RecordingReviewController::class, 'proposal']);
            Route::delete('proposal', [RecordingReviewController::class, 'discardProposal']);

            // Saved as they are made, so closing the tab loses nothing.
            Route::patch('decisions', [RecordingReviewController::class, 'decide']);

            // The region's name goes in the body: detector names carry spaces
            // and colons.
            Route::post('regions', [RecordingReviewController::class, 'addRegion']);
            Route::patch('regions', [RecordingReviewController::class, 'editRegion']);

            // A local output (output_destination "local"), streamed from the
            // service and released there once the browser has saved it.
            Route::get('output', [RecordingReviewController::class, 'output']);
            Route::get('output/audit', [RecordingReviewController::class, 'outputAudit']);
            Route::delete('output', [RecordingReviewController::class, 'releaseOutput']);

            // Encode what was kept. 409 while regions are still undecided.
            Route::post('apply', [RecordingReviewController::class, 'apply']);
        });
    });
