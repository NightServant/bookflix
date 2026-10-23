<?php

namespace Database\Seeders;

use App\Enums\CoverPolicy;
use App\Models\ExternalSource;
use Illuminate\Database\Seeder;

class ExternalSourceSeeder extends Seeder
{
    /**
     * Run the database seeds. `enabled` follows config so a provider stays off until its terms are reviewed.
     */
    public function run(): void
    {
        $sources = [
            'openlibrary' => [
                'name' => 'Open Library',
                'base_url' => 'https://openlibrary.org',
                'attribution' => 'Book data from Open Library (openlibrary.org).',
            ],
            'anilist' => [
                'name' => 'AniList',
                'base_url' => 'https://anilist.co',
                'attribution' => 'Manga, manhwa, manhua and light novel data from AniList (anilist.co).',
            ],
        ];

        foreach ($sources as $key => $attributes) {
            ExternalSource::updateOrCreate(['key' => $key], $attributes + [
                'enabled' => (bool) config("bookflix.providers.{$key}.enabled"),
                'cover_policy' => CoverPolicy::Hotlink,
                'display_policy' => ['show_attribution' => true, 'link_back' => true],
            ]);
        }
    }
}
