<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD = ['all', 'teen', 'mature', 'adult', 'unknown'];

    private const NEW = ['all', 'children', 'teen', 'mature_teen', 'mature', 'adult', 'unknown'];

    /**
     * The audience rating is the existing `media.age_rating` column, widened to the spec's six levels (all ages, children, teen,
     * mature teen, mature, adult) plus unknown. Every stored value keeps its meaning, so no row changes.
     */
    public function up(): void
    {
        $this->constrain(self::NEW);

        Schema::table('media', function ($table) {
            $table->index('age_rating');
        });
    }

    public function down(): void
    {
        Schema::table('media', function ($table) {
            $table->dropIndex(['age_rating']);
        });

        DB::table('media')->where('age_rating', 'children')->update(['age_rating' => 'all']);
        DB::table('media')->where('age_rating', 'mature_teen')->update(['age_rating' => 'teen']);

        $this->constrain(self::OLD);
    }

    /**
     * @param  list<string>  $values
     */
    private function constrain(array $values): void
    {
        $list = implode(', ', array_map(fn (string $value): string => "'{$value}'", $values));

        DB::statement('alter table media drop constraint if exists media_age_rating_check');
        DB::statement("alter table media add constraint media_age_rating_check check (age_rating::text in ({$list}))");
    }
};
