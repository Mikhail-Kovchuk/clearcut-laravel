<?php

/**
 * EXAMPLE — copy into database/migrations/ and adapt.
 *
 * A standalone table, referencing nothing. `subject_type` / `subject_id` name
 * whatever the application calls a recording, so this does not assume the
 * application has a particular table, column or model — only that something
 * identifies the video being processed.
 *
 * The alternative is adding these columns to the table that already holds
 * recordings, which is tidier when there is exactly one such table and makes
 * "has this been redacted" a single query. Either works. This shape is here
 * because it is the one that runs anywhere.
 *
 * What the columns are FOR matters more than their names:
 *
 *   - a claim, so two clicks cannot start two encodes
 *   - the service's job id, so a poller survives a worker restart
 *   - the output and audit keys, because the original is never overwritten and
 *     nothing else records where the result went
 *   - whether a human actually reviewed it
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clearcut_jobs', function (Blueprint $table) {
            $table->id();

            // Whatever the application calls the thing being processed. A
            // polymorphic pair rather than a foreign key: this table should
            // not need to know which model holds recordings, and in some
            // applications more than one does.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // The key in the service's bucket. Recorded because the job needs
            // it on retry, and because it is the only link back to the source
            // once the subject row changes.
            $table->string('source_key', 1024);

            // The service's own ids. Two, because an analysis and the encode
            // that follows it are separate jobs: the review flow fetches the
            // proposal by the analysis id hours after it finished, while the
            // encode id is what a poller asks about.
            $table->string('service_job_id', 64)->nullable()->index();
            $table->string('service_analysis_id', 64)->nullable()->index();

            // queued | running | done | failed | cancelled — mirrors the
            // service's vocabulary so there is nothing to translate, and a
            // mismatch between the two is visible rather than hidden behind a
            // mapping.
            $table->string('state', 20)->default('queued');
            $table->string('stage', 32)->nullable();
            $table->unsignedTinyInteger('progress')->default(0);

            // The claim. Set when work is dispatched, cleared on EVERY exit
            // path — success, failure, cancellation, and the queue's failed()
            // handler, which is the one people forget because a terminated
            // process never reaches a finally block. A claim that is never
            // cleared blocks the subject indefinitely, and the only cure is
            // someone noticing and clearing it by hand.
            //
            // A timestamp rather than a boolean, so a stuck claim can be told
            // from a live one by its age.
            $table->timestamp('claimed_at')->nullable();

            // Where the result landed. The original is never overwritten, so
            // without this the output is unreachable: there is no convention
            // that would let the key be reconstructed.
            $table->string('output_key', 1024)->nullable();

            // Where the audit file landed: source and output hashes, every
            // region with the layer and reason that produced it, the regions
            // rejected for falling outside the frame, and the review flag.
            // The only durable record of what was covered and why — which
            // matters most when somebody asks months later.
            $table->string('audit_key', 1024)->nullable();

            // How many regions were covered, and how many were proposed but
            // could not be resolved. The second is not decoration: a rejected
            // region is one the service refused to guess at, so something on
            // that recording is uncovered and this column is the only place
            // that says so.
            $table->unsignedSmallInteger('regions')->nullable();
            $table->unsignedSmallInteger('rejected_regions')->default(0);

            // Whether a human actually went through the proposal. Set from
            // what happened, never from which code path ran: the tool this
            // was ported from hardcoded it true, which was harmless only
            // while exporting without review was impossible. With an
            // automatic path it would be a false claim in the one record
            // meant to be trustworthy.
            $table->boolean('reviewed_by_human')->default(false);

            // Last failure, so a UI can say why something is unprocessed
            // rather than leaving someone to read worker logs.
            $table->text('error')->nullable();

            // Who asked. Nullable because an automatic pipeline has no user,
            // and no foreign key because the users table is the application's
            // business.
            $table->unsignedBigInteger('requested_by')->nullable();

            $table->timestamps();
            $table->timestamp('finished_at')->nullable();

            $table->index(['subject_type', 'subject_id']);

            // The two questions actually asked of this table: what is running,
            // and what has been stuck long enough to need releasing.
            $table->index(['state', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clearcut_jobs');
    }
};
