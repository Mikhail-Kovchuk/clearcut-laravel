<?php

/**
 * EXAMPLE — for a `clearcut_jobs` table created before cuts were recorded.
 * Published with the create migration by
 *
 *   php artisan vendor:publish --tag=clearcut-migrations
 *
 * and a no-op where the create migration already made the column, so a fresh
 * install can run both.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('clearcut_jobs', 'trimmed')) {
            return;
        }
        Schema::table('clearcut_jobs', function (Blueprint $table) {
            // See the create migration for what it is for.
            $table->boolean('trimmed')->nullable()->after('watermarked');
        });

        // Encodes already finished with a cut asked for say so, or a video
        // only trimmed keeps looking unprocessed. The rest stay null —
        // unknown, not "not trimmed": an apply that sent no cuts used the
        // analysis' own, which the row does not hold.
        DB::table('clearcut_jobs')
            ->where('state', 'done')
            ->whereNotNull('settings')
            ->orderBy('id')
            ->each(function (object $row) {
                $settings = json_decode((string) $row->settings, true);
                if (! empty($settings['cuts'])) {
                    DB::table('clearcut_jobs')->where('id', $row->id)->update(['trimmed' => true]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('clearcut_jobs', function (Blueprint $table) {
            $table->dropColumn('trimmed');
        });
    }
};
