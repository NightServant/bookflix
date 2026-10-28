<?php

namespace Database\Seeders;

use App\Catalog\Taxonomy\Vocabulary;
use App\Models\ContentWarning;
use App\Models\Demographic;
use App\Models\Genre;
use App\Models\Theme;
use App\Models\Trope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TaxonomySeeder extends Seeder
{
    /**
     * Seed the genre tree, themes, tropes, demographics and content warnings from `database/data/taxonomy/vocabulary.csv`
     * (see App\Catalog\Taxonomy\Vocabulary for how listings become rows). Safe to run again: rows are matched on their slug,
     * and only names, structure and order are refreshed, so an edited description is kept and a row dropped from the file is left alone.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->genres();
            $this->upsertNamed(Theme::class, Vocabulary::plain('theme'), ['name']);
            $this->upsertNamed(Trope::class, Vocabulary::tropes(), ['name', 'group', 'sort']);
            $this->upsertNamed(Demographic::class, Vocabulary::plain('demographic'), ['name', 'sort']);
            $this->upsertNamed(ContentWarning::class, Vocabulary::plain('warning'), ['name', 'sort']);
        });
    }

    /**
     * Families first, then their subgenres (which need the family ids), then the extra parent links.
     */
    private function genres(): void
    {
        ['families' => $families, 'genres' => $genres] = Vocabulary::genres();

        Genre::upsert(
            array_map(fn (array $family): array => [
                'name' => $family['name'],
                'slug' => $family['slug'],
                'parent_id' => null,
                'group' => null,
                'sort' => $family['sort'],
                'is_mature' => $family['is_mature'],
            ], $families),
            ['slug'],
            ['name', 'parent_id', 'group', 'sort', 'is_mature'],
        );

        $ids = Genre::query()->pluck('id', 'slug');

        foreach (array_chunk($genres, 200) as $chunk) {
            Genre::upsert(
                array_map(fn (array $genre): array => [
                    'name' => $genre['name'],
                    'slug' => $genre['slug'],
                    'parent_id' => $ids[$genre['family']],
                    'group' => $genre['group'],
                    'sort' => $genre['sort'],
                    'is_mature' => $genre['is_mature'],
                ], $chunk),
                ['slug'],
                ['name', 'parent_id', 'group', 'sort', 'is_mature'],
            );
        }

        $ids = Genre::query()->pluck('id', 'slug');
        $links = [];

        foreach ($genres as $genre) {
            foreach ($genre['also'] as $also) {
                $links[] = [
                    'genre_id' => $ids[$genre['slug']],
                    'parent_id' => $ids[$also['family']],
                    'group' => $also['group'],
                    'sort' => $also['sort'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DB::table('genre_secondary_parents')->upsert($links, ['genre_id', 'parent_id'], ['group', 'sort', 'updated_at']);
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<array<string, mixed>>  $items  Each with a `slug` and the columns to refresh.
     * @param  list<string>  $update
     */
    private function upsertNamed(string $model, array $items, array $update): void
    {
        $columns = array_flip(['name', 'slug', ...$update]);

        $model::upsert(
            array_map(fn (array $item): array => array_intersect_key($item, $columns), $items),
            ['slug'],
            $update,
        );
    }
}
