<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per reader and story (A6): where it stands (`status`) and whether it is a favorite (D-6, a favorite is a library row
     * with `favorited_at` set). `created_at` is when the story was added. Deleting the reader or the media deletes the row.
     */
    public function up(): void
    {
        Schema::create('user_library', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->index()->constrained('media')->cascadeOnDelete();
            $table->enum('status', ['want_to_read', 'reading', 'completed', 'paused', 'abandoned'])->default('want_to_read');
            $table->timestamp('favorited_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'media_id']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_library');
    }
};
