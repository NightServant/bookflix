<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A history row written by a reader save remembers the file it was read in, so a later save extends only a row of the same file and never a manual log's row.
     */
    public function up(): void
    {
        Schema::table('reading_history', function (Blueprint $table) {
            $table->foreignId('content_source_id')->nullable()->constrained('content_sources')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reading_history', function (Blueprint $table) {
            $table->dropConstrainedForeignId('content_source_id');
        });
    }
};
