<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Category and niche describe the lead's market, and the company URL is the
 * company's LinkedIn page. All three arrived inside `extra` until now, which
 * made them unreadable at a glance and impossible to filter or sort by.
 * They are ordinary columns now, and whatever a source had already sent
 * under `extra` is copied across so nothing already on file is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('category')->nullable()->index()->after('company');
            $table->string('niche')->nullable()->index()->after('category');
            $table->string('company_url')->nullable()->after('niche');
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

                    $found = [];

                    // Every source's bag is looked in; the first one to say
                    // anything wins, which is the same rule the ingest uses.
                    foreach ($extra as $values) {
                        if (! is_array($values)) {
                            continue;
                        }

                        foreach (['category', 'niche', 'company_url'] as $column) {
                            if (! isset($found[$column]) && filled($values[$column] ?? null) && is_scalar($values[$column])) {
                                $found[$column] = (string) $values[$column];
                            }
                        }
                    }

                    if ($found !== []) {
                        DB::table('contacts')->where('id', $contact->id)->update($found);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['category', 'niche', 'company_url']);
        });
    }
};
