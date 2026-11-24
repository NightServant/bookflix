<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One model-written summary per media (P11-R5). `source_hash` is the sha256 of the description it was written from.
     */
    public function up(): void
    {
        Schema::create('media_ai_summaries', function (Blueprint $table) {
            $table->foreignId('media_id')->primary()->constrained('media')->cascadeOnDelete();
            $table->text('summary');
            $table->string('source_hash', 64);
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->timestamp('generated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_ai_summaries');
    }
};
