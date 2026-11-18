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
     * A reader's review of a story (P10-R7). One live review per reader and story: the unique index is partial, so a deleted review (soft) doesn't block writing a new one.
     */
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('has_spoilers')->default(false);
            $table->enum('status', ['published', 'hidden'])->default('published');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['media_id', 'status', 'created_at']);
        });

        DB::statement('create unique index reviews_user_media_live_unique on reviews (user_id, media_id) where deleted_at is null');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
