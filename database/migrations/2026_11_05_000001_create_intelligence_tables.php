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
     * The intelligence block of A6. Python writes `model_versions`, `media_features`, `media_lsa_vectors` and `media_similarities` straight to
     * Postgres, so the column names here are a contract (ml/tests/expected_columns.json). Enum-like columns are strings, as `user_interactions.event`
     * is, so a later phase adds a value without a migration. At most one version per name is active, which the partial unique index enforces.
     */
    public function up(): void
    {
        Schema::create('model_versions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('version');
            $table->string('kind');
            $table->string('status');
            $table->timestamp('trained_at')->nullable();
            $table->text('dataset_ref')->nullable();
            $table->jsonb('parameters')->default('{}');
            $table->jsonb('metrics')->default('{}');
            $table->string('artifact_uri')->nullable();
            $table->timestamps();

            $table->unique(['name', 'version']);
        });

        DB::statement("create unique index model_versions_one_active on model_versions (name) where status = 'active'");

        Schema::create('media_features', function (Blueprint $table) {
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('model_version_id')->constrained('model_versions')->cascadeOnDelete();
            $table->jsonb('top_terms')->default('[]');
            $table->text('summary')->nullable();
            $table->timestamp('computed_at');

            $table->primary(['media_id', 'model_version_id']);
        });

        Schema::create('media_lsa_vectors', function (Blueprint $table) {
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('model_version_id')->constrained('model_versions')->cascadeOnDelete();
            $table->vector('vector', 200);

            $table->primary(['media_id', 'model_version_id']);
        });

        DB::statement('create index media_lsa_vectors_vector_idx on media_lsa_vectors using hnsw (vector vector_cosine_ops)');

        Schema::create('media_similarities', function (Blueprint $table) {
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('similar_media_id')->constrained('media')->cascadeOnDelete();
            $table->string('method');
            $table->float('score');
            $table->foreignId('model_version_id')->constrained('model_versions')->cascadeOnDelete();

            $table->primary(['media_id', 'method', 'similar_media_id']);
        });

        DB::statement('alter table media_similarities add constraint media_similarities_not_self check (media_id <> similar_media_id)');
        DB::statement('create index media_similarities_top_idx on media_similarities (media_id, method, score desc)');
        DB::statement('create index media_similarities_similar_idx on media_similarities (similar_media_id)');

        // What a reader was shown, kept for explanations and evaluation. The media goes with the row; a model version is never deleted under it.
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->string('shelf');
            $table->float('score');
            $table->string('reason_type');
            $table->foreignId('reason_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->jsonb('reason_payload')->default('{}');
            $table->foreignId('model_version_id')->constrained('model_versions');
            $table->timestamp('generated_at');

            $table->index(['user_id', 'generated_at']);
            $table->index(['user_id', 'media_id']);
        });

        Schema::create('recommendation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recommendation_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->timestamp('occurred_at');

            $table->index(['recommendation_id', 'event']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recommendation_events');
        Schema::dropIfExists('recommendations');
        Schema::dropIfExists('media_similarities');
        Schema::dropIfExists('media_lsa_vectors');
        Schema::dropIfExists('media_features');
        Schema::dropIfExists('model_versions');
    }
};
