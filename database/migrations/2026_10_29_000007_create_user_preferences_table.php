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
     * What a reader said they like (A6, §41): a media type, genre, theme, trope, demographic or creator, pointed at through the morph map in
     * AppServiceProvider, with a weight for Phase 6 to grow. A reader has each thing once. Deleting the reader deletes the rows; a row whose
     * target is deleted stays until the reader next saves (there is no foreign key across a morph).
     *
     * Everyone who exists before this migration counts as onboarded (D-37): their profile is marked, and a reader who never saved a setting has
     * no profile row yet, so they get one (the table's defaults are what a missing profile already means). Without that row they would be sent
     * to onboarding on their next visit. Readers who register afterwards have no profile until they onboard, skip, or save a setting.
     */
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('preferable');
            $table->smallInteger('weight')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'preferable_type', 'preferable_id']);
        });

        DB::table('profiles')->whereNull('onboarded_at')->update(['onboarded_at' => now()]);

        DB::table('profiles')->insertUsing(
            ['user_id', 'onboarded_at', 'created_at', 'updated_at'],
            DB::table('users')
                ->whereNotExists(fn ($profiles) => $profiles->select(DB::raw(1))->from('profiles')->whereColumn('profiles.user_id', 'users.id'))
                ->selectRaw('users.id, now(), now(), now()'),
        );
    }

    /**
     * Reverse the migrations. The readers marked onboarded stay marked: there is no telling them from the ones who finished it.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
