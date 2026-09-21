<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            // Opened ÷ tracked over the last 7 days, beside the bounce and
            // reply rates. Informational only; it never pauses a mailbox.
            $table->decimal('open_rate_7d', 5, 4)->default(0)->after('reply_rate_7d');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('open_rate_7d');
        });
    }
};
