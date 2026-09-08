<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('dkim_selector')->nullable();
            $table->string('spf_status')->default('unknown');
            $table->string('dkim_status')->default('unknown');
            $table->string('dmarc_status')->default('unknown');
            $table->boolean('dnsbl_listed')->default(false);
            $table->json('dnsbl_zones')->nullable();
            $table->string('health_status')->default('unknown');
            $table->timestamp('last_dns_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('email')->unique();
            $table->string('display_name')->nullable();
            $table->text('google_access_token')->nullable();
            $table->text('google_refresh_token')->nullable();
            $table->timestamp('google_token_expires_at')->nullable();
            $table->json('google_scopes')->nullable();
            $table->string('gmail_history_id')->nullable();
            $table->string('status')->default('active');
            $table->string('paused_reason')->nullable();
            $table->unsignedInteger('daily_cap')->default(40);
            $table->unsignedInteger('min_gap_minutes')->default(3);
            $table->unsignedInteger('max_gap_minutes')->default(15);
            $table->string('send_window_start')->default('09:00');
            $table->string('send_window_end')->default('17:00');
            $table->string('send_timezone')->default('UTC');
            $table->boolean('send_weekends')->default(false);
            $table->boolean('warmup_enabled')->default(true);
            $table->timestamp('warmup_started_at')->nullable();
            $table->unsignedInteger('warmup_start_per_day')->default(5);
            $table->unsignedInteger('warmup_increment_per_day')->default(3);
            $table->decimal('bounce_rate_7d', 5, 4)->default(0);
            $table->decimal('reply_rate_7d', 5, 4)->default(0);
            $table->unsignedInteger('sent_7d')->default(0);
            $table->string('health_status')->default('unknown');
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_send_error')->nullable();
            $table->timestamps();
        });

        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->string('tag')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('sequence_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->unsignedInteger('delay_days')->default(0);
            $table->unsignedInteger('delay_hours')->default(0);
            $table->text('drafting_instructions');
            // Per step, not per automation: a step-level field can express
            // "attach to every email" by repeating it, but an automation-level
            // one cannot express "attach to the first email only".
            // Array of {id, disk, path, filename, mime, size} maps.
            $table->json('attachments')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['automation_id', 'position']);
        });

        /*
         * The person. Not "an email address we were given".
         *
         * The address is optional because the half of this system that finds
         * people works precisely on people whose address nobody has yet. Who
         * somebody is, and whether we can reach them, are two separate facts:
         * `email_status` carries the second one and nothing else does.
         */
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();

            // Nullable and unique together: Postgres allows any number of
            // nulls in a unique index, so people with no address do not
            // collide with each other while two rows can never share one.
            $table->string('email')->nullable()->unique();

            // An ordinary attribute. Finders return it too, so it is not
            // something only the scraper knows about.
            $table->string('profile_url')->nullable()->index();

            // `name` stays alongside the two parts: not every contact splits
            // into two (mononyms, "Support Team"), and callers already send it.
            // The parts are filled beside it, never instead of it.
            $table->string('name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();

            // LinkedIn calls it a headline, Findymail calls it job_title,
            // Hunter calls it position. One name, chosen once.
            $table->string('job_title')->nullable();
            $table->string('company')->nullable();

            /*
             * The bare host, which is what a finder searches by. Stored rather
             * than split out of the address on demand: the waterfall groups
             * work by domain before any address exists.
             */
            $table->string('domain')->nullable()->index();
            $table->string('source')->nullable();

            /*
             * How far the waterfall has got, and the only answer to "may we
             * email this person". Deliberately not paired with a separate
             * sendable flag: two columns saying the same thing drift apart,
             * and then neither can be trusted.
             */
            $table->string('email_status')->default('pending')->index();
            $table->timestamp('email_checked_at')->nullable();

            // Which provider produced the address, so a run of bounces can be
            // traced back to whoever supplied them.
            $table->string('email_provider')->nullable();

            /*
             * Whatever a source sends that we do not model, namespaced by that
             * source. One flat bag would let two providers both reporting a
             * "score" overwrite each other, with no way to tell what a caller
             * claimed from what a provider found.
             */
            $table->json('extra')->nullable();

            $table->timestamps();
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('active');
            $table->unsignedInteger('current_step')->default(0);
            $table->string('gmail_thread_id')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->text('stop_reason')->nullable();
            $table->timestamps();
            $table->index(['contact_id', 'automation_id', 'status']);
            $table->index(['status']);
        });

        /*
         * One OPEN enrollment per (contact, automation).
         *
         * Waiting counts as open. A contact who arrived with no address sits
         * in `waiting_email` holding their place, and without it here a caller
         * pushing the same person twice while they wait would enroll them
         * twice, then send two copies of the sequence the moment the address
         * came back.
         *
         * Partial unique indexes are a Postgres feature, and everything runs
         * on Postgres, the test suite included. EnrollmentActivator checks
         * before inserting, so a caller gets an answer rather than a
         * constraint violation; this is what makes the rule true regardless.
         */
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX enrollments_active_unique ON enrollments (contact_id, automation_id) '
                ."WHERE status IN ('active', 'waiting_email')"
            );
        }

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sequence_step_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('drafting');
            $table->string('subject')->nullable();
            $table->text('body_text')->nullable();
            $table->string('ai_subject')->nullable();
            $table->text('ai_body')->nullable();
            $table->boolean('edited_by_user')->default(false);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sending_started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('gmail_message_id')->nullable();
            $table->string('gmail_thread_id')->nullable();
            $table->string('rfc_message_id')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->timestamps();
            $table->unique(['enrollment_id', 'sequence_step_id']);
            $table->index(['status', 'scheduled_at']);
            $table->index(['mailbox_id', 'scheduled_at']);
            $table->index(['mailbox_id', 'sent_at']);
        });

        Schema::create('replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('gmail_message_id')->unique();
            $table->string('gmail_thread_id')->nullable();
            $table->string('from_email')->nullable();
            $table->string('subject')->nullable();
            $table->text('snippet')->nullable();
            $table->text('body_text')->nullable();
            $table->string('classification')->default('reply');
            $table->timestamp('received_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['classification', 'read_at']);
        });

        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('reason');
            $table->foreignId('source_reply_id')->nullable()->constrained('replies')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('health_checks', function (Blueprint $table) {
            $table->id();
            $table->morphs('checkable');
            $table->string('check_type');
            $table->string('status');
            $table->json('detail')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
            $table->index(['check_type', 'checked_at']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('subject');
            $table->string('event');
            $table->string('level')->default('info');
            $table->text('message');
            $table->json('context')->nullable();
            $table->boolean('retryable')->default(false);
            $table->timestamp('retried_at')->nullable();
            $table->timestamps();
            $table->index(['level', 'created_at']);
            $table->index(['event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('health_checks');
        Schema::dropIfExists('suppressions');
        Schema::dropIfExists('replies');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('sequence_steps');
        Schema::dropIfExists('automations');
        Schema::dropIfExists('mailboxes');
        Schema::dropIfExists('domains');
    }
};
