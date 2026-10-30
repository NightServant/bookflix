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
     * A reader's own score for a story (D-14, D-36): a whole number from 1 to 10 (a CHECK holds the range, whatever writes the row), one per
     * reader and story, so the primary key is the pair. A story does not have to be in the library to be rated. Deleting the reader or the
     * media deletes the row; `media_id` is indexed for the aggregate job, which reads every rating of one story.
     */
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->smallInteger('score');
            $table->timestamps();

            $table->primary(['user_id', 'media_id']);
            $table->index('media_id');
        });

        DB::statement('alter table ratings add constraint ratings_score_range check (score between 1 and 10)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
