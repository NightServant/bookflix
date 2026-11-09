<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The daily prune finds the rows older than the retention by this column alone.
     */
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->index('generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropIndex(['generated_at']);
        });
    }
};
