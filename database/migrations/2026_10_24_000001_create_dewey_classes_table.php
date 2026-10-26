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
        Schema::create('dewey_classes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('parent_id')->nullable()->index()->constrained('dewey_classes')->restrictOnDelete();
            $table->smallInteger('level');
            $table->string('path');
            $table->timestamps();
        });

        DB::statement('create index dewey_classes_path_pattern on dewey_classes (path varchar_pattern_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dewey_classes');
    }
};
