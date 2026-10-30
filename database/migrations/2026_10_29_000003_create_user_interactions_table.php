<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The recommender's signal log (A6, spec §44), written only by InteractionRecorder. It holds no IP address and no user agent, and no
     * `updated_at`: a row is never changed. `media_id` goes null when the media is deleted for good, so the event still counts.
     */
    public function up(): void
    {
        Schema::create('user_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('event');
            $table->float('value', 24)->nullable();
            $table->jsonb('context')->default('{}');
            $table->timestamp('occurred_at');

            $table->index(['user_id', 'occurred_at']);
            $table->index(['media_id', 'event', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_interactions');
    }
};
