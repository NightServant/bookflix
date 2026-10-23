<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('avatar_path')->nullable();
            $table->text('bio')->nullable();
            $table->jsonb('preferred_languages')->default('[]');
            $table->enum('reading_length', ['short', 'medium', 'long', 'any'])->default('any');
            $table->enum('profile_visibility', ['private', 'public'])->default('private');
            $table->enum('library_visibility', ['private', 'public'])->default('private');
            $table->boolean('personalization_enabled')->default(true);
            $table->timestamp('onboarded_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
