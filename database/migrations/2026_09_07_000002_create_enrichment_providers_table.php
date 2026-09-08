<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The email waterfall itself, as data.
 *
 * Which providers run, in what order, and whether they run at all are decisions
 * that change with what they cost and how well they do. Kept in the database so
 * a provider burning money or returning rubbish can be switched off while it is
 * happening, rather than at the speed of a deploy.
 *
 * What a provider charges is deliberately NOT here. That belongs to the
 * provider, so it is stated once beside the driver that talks to them and every
 * account using it reports the same figure. A per-row rate was a setting nobody
 * wanted and everybody could get wrong, and the cost-per-answer page is built
 * on it, so a typo there produces a number that still looks like an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrichment_providers', function (Blueprint $table) {
            $table->id();

            // What it is called here. Free text, because two rows can use the
            // same driver against different accounts or plans.
            $table->string('name');

            // Which implementation runs. Maps to a class in config/enrichment.php.
            $table->string('driver');

            // find: turns a person into an email address.
            // verify: decides whether an address can actually receive mail.
            $table->string('kind');

            // Position in the waterfall, within its kind. Lower runs first.
            $table->unsignedSmallInteger('position')->default(0);

            $table->boolean('enabled')->default(true);

            // Encrypted: these are live billing credentials, and the dashboard
            // has no business showing them back to anyone once saved.
            $table->text('credentials')->nullable();

            /*
             * Why the system switched it off, as opposed to a person doing so.
             *
             * A provider that has run out of credit or started refusing every
             * request must stop being tried, or every contact behind it fails
             * on the way to a provider that would have worked. Recording the
             * reason is the difference between "we turned this off" and "it
             * broke".
             */
            $table->string('disabled_reason')->nullable();
            $table->timestamp('disabled_at')->nullable();

            $table->timestamps();

            $table->index(['kind', 'enabled', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_providers');
    }
};
