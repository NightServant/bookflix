<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Learning to rank is precomputed (P8-R2): a queued job writes each active reader's top hybrid candidates with the features the ranker gave
     * them to `ltr_candidates`; the Python service scores them into `ltr_scores`; a request only reads `ltr_scores`. One row per reader and title,
     * replaced as a whole each night. Both go with the reader and the media.
     */
    public function up(): void
    {
        Schema::create('ltr_candidates', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->jsonb('features')->default('{}');
            $table->timestamp('generated_at');

            $table->primary(['user_id', 'media_id']);
            $table->index('generated_at');
        });

        Schema::create('ltr_scores', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->float('score');
            $table->foreignId('model_version_id')->constrained('model_versions')->cascadeOnDelete();
            $table->timestamp('generated_at');

            $table->primary(['user_id', 'media_id']);
        });

        DB::statement('alter table ltr_scores alter column score type real');
        DB::statement('create index ltr_scores_user_score_idx on ltr_scores (user_id, score desc)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ltr_scores');
        Schema::dropIfExists('ltr_candidates');
    }
};
