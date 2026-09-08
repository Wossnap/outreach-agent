<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per call made to a provider, whether it found anything or not.
 *
 * This is what answers the question the ticket actually asks: what does each
 * provider cost, and how often is it any good. A provider that is cheap per
 * call and finds nothing is expensive per address found, and there is no way to
 * see that without recording the misses as well as the hits.
 *
 * It is also where a bounce is recorded once the outreach side reports one, so
 * the "we said valid" and "it did not deliver" halves of the question finally
 * sit in the same table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_lookups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();

            // Nullable, and the driver is stored alongside it: a provider row
            // can be deleted, and the history of what it cost us should not go
            // with it.
            $table->foreignId('enrichment_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_name');
            $table->string('driver');

            $table->string('kind');
            $table->string('result');

            // Charged rather than listed: providers that bill only for a hit
            // record zero on a miss, and averaging the list price would
            // overstate what they cost.
            $table->decimal('cost', 12, 6)->default(0);

            $table->unsignedInteger('duration_ms')->nullable();

            // Whatever the provider said, for reading back when a verdict looks
            // wrong. No schema imposed: every provider answers differently.
            $table->json('detail')->nullable();

            $table->timestamps();

            $table->index(['driver', 'kind', 'result']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_lookups');
    }
};
