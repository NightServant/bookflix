<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Where a reader is in a story (A6): one `reading_progress` row per reader and story, keyed by both, holding the last place they logged
     * (a percent from 0 to 100, kept to two decimals, and optionally a page, a chapter, or the reader's own locator for Phase 9) and when.
     * `reading_history` is one row per logged session, never changed: the span of time, how many seconds, and the percent it moved from and to.
     * Deleting the reader or the media deletes both; `content_source_id` goes null if the source is removed.
     */
    public function up(): void
    {
        Schema::create('reading_progress', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('content_source_id')->nullable()->constrained('content_sources')->nullOnDelete();
            $table->jsonb('locator')->nullable();
            $table->unsignedInteger('page')->nullable();
            $table->string('chapter', 100)->nullable();
            $table->decimal('percent', 5, 2)->default(0);
            $table->timestamp('last_read_at');

            $table->primary(['user_id', 'media_id']);
            $table->index(['user_id', 'last_read_at']);
            $table->index('media_id');
        });

        DB::statement('alter table reading_progress add constraint reading_progress_percent_range check (percent >= 0 and percent <= 100)');

        Schema::create('reading_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('seconds')->default(0);
            $table->decimal('from_percent', 5, 2)->default(0);
            $table->decimal('to_percent', 5, 2)->default(0);
            $table->unsignedSmallInteger('chapters_completed')->default(0);

            $table->index(['user_id', 'started_at']);
            $table->index('media_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reading_history');
        Schema::dropIfExists('reading_progress');
    }
};
