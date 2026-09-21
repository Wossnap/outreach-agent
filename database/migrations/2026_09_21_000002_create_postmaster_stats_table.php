<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What Gmail Postmaster Tools says about each sending domain, one row per
 * domain per day. The named columns are the metrics the dashboard reads;
 * `raw` keeps every metric returned so nothing has to be re-fetched when a
 * new one becomes interesting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postmaster_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // Fractions, not percentages: 0.0012 is 0.12%.
            $table->decimal('spam_rate', 8, 6)->nullable();
            $table->decimal('auth_success_rate', 8, 6)->nullable();
            $table->decimal('delivery_error_rate', 8, 6)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->unique(['domain_id', 'date']);
            $table->index('date');
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->timestamp('postmaster_synced_at')->nullable();
            // A sentence a person can act on, or null when the last sync worked.
            $table->text('postmaster_error')->nullable();
            $table->string('postmaster_verification')->nullable();
            $table->json('postmaster_compliance')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['postmaster_synced_at', 'postmaster_error', 'postmaster_verification', 'postmaster_compliance']);
        });

        Schema::dropIfExists('postmaster_stats');
    }
};
