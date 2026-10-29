<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A reader's own lists of stories (A6). The slug is unique per owner, not overall: two readers may both keep a "Favorites". An editorial
     * collection (`is_editorial`, set by an admin) is a staff pick that every reader may open, and Discover and Home show it as a themed row.
     * Items are ordered by `position`, contiguous from 0; a story is in a collection once. Deleting the owner, the collection or the media
     * deletes the rows that hung from it.
     */
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 120);
            $table->text('description')->nullable();
            $table->enum('visibility', ['private', 'public'])->default('private');
            $table->boolean('is_editorial')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
            $table->index(['is_editorial', 'updated_at']);
            $table->index('slug');
        });

        Schema::create('collection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->index()->constrained('media')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['collection_id', 'media_id']);
            $table->index(['collection_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_items');
        Schema::dropIfExists('collections');
    }
};
