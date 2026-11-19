<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Outside reviews and ratings (P10-R20), read from a provider's official API. A review is one per source and external id; a moderator's
     * hide (`hidden_at`, `hidden_by`) survives a refresh. A rating is one per media and source.
     */
    public function up(): void
    {
        Schema::create('external_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->string('source', 32);
            $table->string('external_id');
            $table->string('author_name')->nullable();
            $table->smallInteger('rating')->nullable();
            $table->text('excerpt');
            $table->string('url', 2048);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index(['media_id', 'hidden_at']);
        });

        Schema::create('external_ratings', function (Blueprint $table) {
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->string('source', 32);
            $table->smallInteger('score');
            $table->integer('votes')->nullable();
            $table->string('url', 2048)->nullable();
            $table->timestamp('fetched_at');

            $table->primary(['media_id', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_ratings');
        Schema::dropIfExists('external_reviews');
    }
};
