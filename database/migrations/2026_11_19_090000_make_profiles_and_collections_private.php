<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Privacy becomes the schema (P10-R3): profiles, libraries and collections are visible to their owner only, so the visibility columns go. Editorial
     * collections are the only ones readers share, and only admins make them: a collection of a reader that was picked as a staff pick stops being
     * one, as readers' collections are never republished.
     */
    public function up(): void
    {
        DB::table('collections')
            ->where('is_editorial', true)
            ->whereNotIn('user_id', DB::table('users')->where('is_admin', true)->select('id'))
            ->update(['is_editorial' => false]);

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['profile_visibility', 'library_visibility']);
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn('visibility');
        });
    }

    /**
     * Reverse the migrations. The columns come back private; what was public before is not remembered, and the staff picks that ended stay ended.
     */
    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->enum('profile_visibility', ['private', 'public'])->default('private');
            $table->enum('library_visibility', ['private', 'public'])->default('private');
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->enum('visibility', ['private', 'public'])->default('private');
        });
    }
};
