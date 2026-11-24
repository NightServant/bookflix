<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The messages of a saved chat. `fixed` marks an app line (a refusal or "not available") and the question it answered: both are shown and kept,
     * but never sent back to the model.
     */
    public function up(): void
    {
        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('assistant_conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->string('role', 16);
            $table->text('text');
            $table->jsonb('story_ids')->default('[]');
            $table->string('mode', 32)->nullable();
            $table->boolean('used_library')->default(false);
            $table->boolean('fixed')->default(false);
            $table->jsonb('attachment_names')->default('[]');
            $table->timestamp('created_at')->nullable();

            $table->index(['assistant_conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
    }
};
