<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The place of a served title within its shelf, from 0. Learning to rank trains on it (as a feature at training only, since where a tile sat
     * decides whether it was seen); rows logged before this column have null and are never used for training.
     */
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->unsignedSmallInteger('position')->nullable()->after('shelf');
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
