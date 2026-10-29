<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The reader's own choice to see mature and adult titles. Off for everyone until they turn it on and confirm they are 18 or older;
     * the confirmation is stored with the moment it was given, and both have to be present for access (see App\Access\MatureAccess).
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->boolean('show_mature')->default(false)->after('personalization_enabled');
            $table->timestamp('adult_confirmed_at')->nullable()->after('show_mature');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['show_mature', 'adult_confirmed_at']);
        });
    }
};
