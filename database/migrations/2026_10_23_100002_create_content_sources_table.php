<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Designed now (spec §6), unused until Phase 9.
     */
    public function up(): void
    {
        Schema::create('content_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->index()->constrained('media')->cascadeOnDelete();
            $table->enum('access_type', ['public_domain', 'licensed', 'user_owned', 'external_link']);
            $table->enum('format', ['epub', 'pdf', 'cbz', 'cbr', 'images', 'url']);
            $table->string('storage_path')->nullable();
            $table->text('url')->nullable();
            $table->text('license');
            $table->string('rights_holder')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_sources');
    }
};
