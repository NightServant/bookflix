<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * When `bookflix:sync-signals` last asked a signal source about a media, hit or miss, so each run
     * resumes with the media checked longest ago instead of asking about the same ones again.
     */
    public function up(): void
    {
        Schema::create('media_signal_checks', function (Blueprint $table) {
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('external_source_id')->constrained('external_sources');
            $table->timestampTz('checked_at');

            $table->primary(['media_id', 'external_source_id']);
            $table->index(['external_source_id', 'checked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_signal_checks');
    }
};
