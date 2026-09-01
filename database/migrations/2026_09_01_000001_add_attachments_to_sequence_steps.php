<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sequence_steps', function (Blueprint $table) {
            // Per step, not per automation: a step-level field can express
            // "attach to every email" by repeating it, but an automation-level
            // one cannot express "attach to the first email only".
            // Array of {id, disk, path, filename, mime, size} maps.
            $table->json('attachments')->nullable()->after('drafting_instructions');
        });
    }

    public function down(): void
    {
        Schema::table('sequence_steps', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });
    }
};
