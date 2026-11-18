<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The reader's browser makes a random id for each page load and numbers its saves 1, 2, 3 within it (P9-R13). Saves of one page load are ordered by that
     * number, so a late retry can't overwrite a newer place; saves of different page loads are ordered by arrival. No clock is read, so a wrong one can't matter.
     */
    public function up(): void
    {
        Schema::table('reading_progress', function (Blueprint $table) {
            $table->string('client_session', 64)->nullable();
            $table->unsignedBigInteger('client_seq')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reading_progress', function (Blueprint $table) {
            $table->dropColumn(['client_session', 'client_seq']);
        });
    }
};
