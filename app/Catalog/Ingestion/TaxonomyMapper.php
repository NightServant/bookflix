<?php

namespace App\Catalog\Ingestion;

use App\Catalog\Data\NormalizedMedia;
use Illuminate\Support\Str;

class TaxonomyMapper
{
    /**
     * Provider term lookups (`database/data/taxonomy/<provider>.csv`), by provider then lowercased term.
     *
     * @var array<string, array<string, array{kind: string, slug: string}>>
     */
    private array $mappings = [];

    /**
     * Bookflix taxonomy slugs for a record's provider terms. Terms the provider's CSV does not map become tags.
     *
     * @return array{genres: list<string>, themes: list<string>, tags: list<string>}
     */
    public function map(string $provider, NormalizedMedia $media): array
    {
        $mapping = $this->mapping($provider);
        $mapped = ['genres' => [], 'themes' => [], 'tags' => []];

        foreach ([...$media->genres, ...$media->themes, ...$media->tags] as $term) {
            $entry = $mapping[Str::lower(trim($term))] ?? ['kind' => 'tag', 'slug' => Str::slug($term)];

            if ($entry['slug'] !== '') {
                $mapped[$entry['kind'].'s'][] = $entry['slug'];
            }
        }

        return [
            'genres' => array_values(array_unique($mapped['genres'])),
            'themes' => array_values(array_unique($mapped['themes'])),
            'tags' => array_values(array_unique($mapped['tags'])),
        ];
    }

    /**
     * @return array<string, array{kind: string, slug: string}>
     */
    private function mapping(string $provider): array
    {
        if (isset($this->mappings[$provider])) {
            return $this->mappings[$provider];
        }

        $path = database_path("data/taxonomy/{$provider}.csv");
        $lines = is_file($path) ? array_slice(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], 1) : [];
        $mapping = [];

        foreach ($lines as $line) {
            [$term, $kind, $slug] = str_getcsv($line, escape: '');
            $mapping[Str::lower(trim($term))] = ['kind' => $kind, 'slug' => $slug];
        }

        return $this->mappings[$provider] = $mapping;
    }
}
