<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Open tracking. The token is what the pixel URL carries, assigned at send
 * time so a draft that never sends never has one. The counts are what the
 * dashboard reads; they are directional (see config/outreach.php).
 *
 * The [status, sent_at] index is for the dashboard's time series, which scans
 * every sent message in a window; only [mailbox_id, sent_at] existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('open_token', 64)->nullable()->unique()->after('rfc_message_id');
            $table->timestamp('first_opened_at')->nullable()->after('open_token');
            $table->timestamp('last_opened_at')->nullable()->after('first_opened_at');
            $table->unsignedInteger('open_count')->default(0)->after('last_opened_at');
            $table->index(['status', 'sent_at']);
        });

        Schema::table('replies', function (Blueprint $table) {
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('replies', function (Blueprint $table) {
            $table->dropIndex(['received_at']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['status', 'sent_at']);
            $table->dropColumn(['open_token', 'first_opened_at', 'last_opened_at', 'open_count']);
        });
    }
};
