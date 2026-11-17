<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The reader's browser stamps each save with its own clock in milliseconds (P9-R13). Saves are ordered by that stamp against the one stored here,
     * never against the server's clock, so a device whose clock is wrong still saves and a late retry can't overwrite a newer place.
     */
    public function up(): void
    {
        Schema::table('reading_progress', function (Blueprint $table) {
            $table->unsignedBigInteger('client_ts')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reading_progress', function (Blueprint $table) {
            $table->dropColumn('client_ts');
        });
    }
};
