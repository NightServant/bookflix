<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Content warnings are a vocabulary plus a per media status: present, absent (verified absent), unknown or disputed.
     * A media without a row for a warning is unknown, never absent. The old `media.content_warnings` array stays readable until the filters switch over.
     */
    public function up(): void
    {
        Schema::create('content_warnings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('media_content_warnings', function (Blueprint $table) {
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('content_warning_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['present', 'absent', 'unknown', 'disputed'])->default('unknown');
            $table->string('source')->nullable();
            $table->timestamps();
            $table->primary(['media_id', 'content_warning_id']);
            $table->index(['content_warning_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_content_warnings');
        Schema::dropIfExists('content_warnings');
    }
};
