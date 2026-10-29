<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two more facets that sit beside genres and themes: story tropes (searchable on their own) and the editorial
     * demographic (shonen, seinen, young adult...), which is not an age rating (that stays `media.age_rating`).
     */
    public function up(): void
    {
        Schema::create('tropes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('group')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('media_tropes', function (Blueprint $table) {
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('trope_id')->index()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['media_id', 'trope_id']);
        });

        Schema::create('demographics', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('media_demographics', function (Blueprint $table) {
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('demographic_id')->index()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['media_id', 'demographic_id']);
        });
    }

    public function down(): void
    {
        foreach (['media_demographics', 'demographics', 'media_tropes', 'tropes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
