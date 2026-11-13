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
     * One e5 sentence embedding per media and `embedding` model version, written by the Python `embed-media` job (the column names are a contract,
     * ml/tests/expected_columns.json). `content_hash` is the hash of the text the vector was made from, so the job re-embeds only what changed. The
     * column is 384-dimensional: a model with other dimensions gets its own table (A6), never this one.
     */
    public function up(): void
    {
        Schema::create('media_embeddings', function (Blueprint $table) {
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('model_version_id')->constrained('model_versions')->cascadeOnDelete();
            $table->vector('embedding', 384);
            $table->string('content_hash', 64);

            $table->primary(['media_id', 'model_version_id']);
        });

        DB::statement('create index media_embeddings_embedding_idx on media_embeddings using hnsw (embedding vector_cosine_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_embeddings');
    }
};
