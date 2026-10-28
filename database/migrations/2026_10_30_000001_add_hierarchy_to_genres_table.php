<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Genres become a tree: a family (parent_id null) holds subgenres, which carry a display group.
     * A subgenre the spec lists under two families is one row with a primary parent, plus a link row per extra parent.
     */
    public function up(): void
    {
        Schema::table('genres', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('slug')->constrained('genres')->restrictOnDelete();
            $table->string('group')->nullable()->after('parent_id');
            $table->unsignedSmallInteger('sort')->default(0)->after('group');
            $table->boolean('is_mature')->default(false)->after('sort');
            $table->text('description')->nullable()->after('is_mature');
            $table->index(['parent_id', 'sort']);
        });

        Schema::create('genre_secondary_parents', function (Blueprint $table) {
            $table->foreignId('genre_id')->constrained('genres')->cascadeOnDelete();
            $table->foreignId('parent_id')->index()->constrained('genres')->cascadeOnDelete();
            $table->string('group')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->primary(['genre_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('genre_secondary_parents');

        Schema::table('genres', function (Blueprint $table) {
            $table->dropIndex(['parent_id', 'sort']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['group', 'sort', 'is_mature', 'description']);
        });
    }
};
