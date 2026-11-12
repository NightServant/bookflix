<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One extractive summary per media with a long description, written by the Python `summarize-media` job (the column names are a contract,
     * ml/tests/expected_columns.json). It is not keyed by a model version, unlike `media_features`, whose rows follow the LSA version and are
     * pruned weekly: a summary depends only on its description, and `source_hash` (of that description) tells the job when to redo it.
     */
    public function up(): void
    {
        Schema::create('media_summaries', function (Blueprint $table) {
            $table->foreignId('media_id')->primary()->constrained('media')->cascadeOnDelete();
            $table->text('summary');
            $table->string('source_hash', 64);
            $table->string('method', 32);
            $table->timestamp('generated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_summaries');
    }
};
