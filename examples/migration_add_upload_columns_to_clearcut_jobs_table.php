<?php

/**
 * EXAMPLE — for a `clearcut_jobs` table created before recordings could be
 * sent from someone's computer. Published with the create migration by
 *
 *   php artisan vendor:publish --tag=clearcut-migrations
 *
 * and a no-op where the create migration already made these columns, so a
 * fresh install can run both.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clearcut_jobs', function (Blueprint $table) {
            // See the create migration for what each is for.
            if (! Schema::hasColumn('clearcut_jobs', 'source_name')) {
                $table->string('source_name')->nullable()->after('source_key');
            }
            if (! Schema::hasColumn('clearcut_jobs', 'saved_at')) {
                $table->timestamp('saved_at')->nullable()->after('placed_at');
            }
            if (! Schema::hasColumn('clearcut_jobs', 'dismissed_at')) {
                $table->timestamp('dismissed_at')->nullable()->after('saved_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clearcut_jobs', function (Blueprint $table) {
            $table->dropColumn(['source_name', 'saved_at', 'dismissed_at']);
        });
    }
};
