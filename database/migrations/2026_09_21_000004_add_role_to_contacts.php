<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The lead's role, as the source classifies it ("Owner", "Marketing lead"):
 * a short label to filter on, beside the free-text job title LinkedIn gives.
 * Anything a source already sent as `extra.*.role` is copied across.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('role')->nullable()->index()->after('job_title');
        });

        DB::table('contacts')
            ->whereNotNull('extra')
            ->select(['id', 'extra'])
            ->orderBy('id')
            ->chunk(500, function ($contacts) {
                foreach ($contacts as $contact) {
                    $extra = json_decode($contact->extra, true);

                    if (! is_array($extra)) {
                        continue;
                    }

                    foreach ($extra as $values) {
                        if (is_array($values) && filled($values['role'] ?? null) && is_scalar($values['role'])) {
                            DB::table('contacts')->where('id', $contact->id)->update(['role' => (string) $values['role']]);

                            break;
                        }
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
