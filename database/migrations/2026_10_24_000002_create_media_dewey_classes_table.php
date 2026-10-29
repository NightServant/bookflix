<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('media_dewey_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('dewey_class_id')->index()->constrained()->restrictOnDelete();
            $table->enum('classification_type', ['primary', 'secondary']);
            $table->decimal('confidence', 4, 3);
            $table->string('source');
            $table->enum('classification_method', ['manual', 'automatic', 'external']);
            // ponytail: no foreign key until Phase 6 creates model_versions.
            $table->unsignedBigInteger('model_version_id')->nullable();
            $table->enum('review_status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['media_id', 'dewey_class_id']);
        });

        DB::statement("create unique index media_dewey_primary_unique on media_dewey_classes (media_id) where classification_type = 'primary' and review_status <> 'rejected'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_dewey_classes');
    }
};
