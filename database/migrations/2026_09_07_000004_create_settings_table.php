<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings somebody changes while the system is running.
 *
 * Not config files, which need a deploy to change. What lives here is the
 * switch that stops the waterfall spending money and the one that decides
 * whether an address must be confirmed before anything is sent to it. Both are
 * wanted at short notice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            // JSON rather than a string, so a setting that is a number or a
            // list later does not need its own column or its own parsing.
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
