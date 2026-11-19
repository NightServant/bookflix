<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The extractive summary of a story's published reviews (P10-R11). One row per story; no row means no summary is shown.
     */
    public function up(): void
    {
        Schema::create('review_summaries', function (Blueprint $table) {
            $table->foreignId('media_id')->primary()->constrained('media')->cascadeOnDelete();
            $table->text('summary');
            $table->integer('review_count');
            $table->string('source_hash', 64);
            $table->timestamp('generated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('review_summaries');
    }
};
