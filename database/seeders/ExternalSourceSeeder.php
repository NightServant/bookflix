<?php

namespace Database\Seeders;

use App\Enums\CoverPolicy;
use App\Models\ExternalSource;
use Illuminate\Database\Seeder;

class ExternalSourceSeeder extends Seeder
{
    /**
     * Run the database seeds. Existing sources are left exactly as they are, so a re-seed never flips `enabled` back:
     * the config value only sets a new source's starting state, and the column is the source of truth from then on.
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
            'mangadex' => [
                'name' => 'MangaDex',
                'base_url' => 'https://mangadex.org',
                'attribution' => 'Manga data and covers from MangaDex (mangadex.org).',
            ],
            'mangaupdates' => [
                'name' => 'MangaUpdates',
                'base_url' => 'https://www.mangaupdates.com',
                'attribution' => 'Series data from MangaUpdates (mangaupdates.com).',
            ],
            'comicvine' => [
                'name' => 'Comic Vine',
                'base_url' => 'https://comicvine.gamespot.com',
                'attribution' => 'Comic data from Comic Vine (comicvine.gamespot.com).',
            ],
            'metron' => [
                'name' => 'Metron',
                'base_url' => 'https://metron.cloud',
                'attribution' => 'Comic data from Metron (metron.cloud).',
            ],
            'hardcover' => [
                'name' => 'Hardcover',
                'base_url' => 'https://hardcover.app',
                'attribution' => 'Book data from Hardcover (hardcover.app).',
            ],
            'wikidata' => [
                'name' => 'Wikidata',
                'base_url' => 'https://www.wikidata.org',
                'attribution' => 'Linked data from Wikidata (wikidata.org), available under CC0.',
            ],
            'wikipedia' => [
                'name' => 'Wikipedia',
                'base_url' => 'https://en.wikipedia.org',
                'attribution' => 'Summaries from Wikipedia (en.wikipedia.org), available under CC BY-SA.',
            ],
        ];

        foreach ($sources as $key => $attributes) {
            ExternalSource::firstOrCreate(['key' => $key], $attributes + [
                'enabled' => (bool) config("bookflix.providers.{$key}.enabled"),
                'cover_policy' => CoverPolicy::Hotlink,
                'display_policy' => ['show_attribution' => true, 'link_back' => true],
            ]);
        }
    }
}
