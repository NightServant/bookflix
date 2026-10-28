<?php

namespace App\Discovery\Search;

use App\Enums\AgeRating;
use App\Models\ContentWarning;
use App\Models\Demographic;
use App\Models\Genre;
use App\Models\Theme;
use App\Models\Trope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The taxonomy as plain arrays for a filter UI, kept ten minutes: the nested genre tree, and the flat lists of themes, tropes, demographics,
 * audience ratings and content warnings. Nothing returned here is a model, so it caches and serializes without surprises.
 */
class TaxonomyFacets
{
    private const TTL = 600;

    /** Every cache key this class and the pages built on the taxonomy use, so seeding can drop them together. */
    public const KEYS = [
        'taxonomy:genres:v1:public', 'taxonomy:genres:v1:all', 'taxonomy:themes:v1', 'taxonomy:tropes:v1', 'taxonomy:demographics:v1', 'taxonomy:warnings:v1',
        'search:facet-genres:v2', 'search:facet-themes:v1', 'discover:genre-counts:v2',
    ];

    public static function flush(): void
    {
        foreach (self::KEYS as $key) {
            Cache::forget($key);
        }
    }

    /**
     * The genre families in spec order, each with its subgenres in display groups (the group is null for a family with no groups).
     * A subgenre listed under two families appears under both. Mature-gated genres are left out unless asked for; asking is the caller's
     * decision to make from the reader's access, never from a filter.
     *
     * @return list<array{slug: string, name: string, is_mature: bool, groups: list<array{name: string|null, items: list<array{slug: string, name: string, is_mature: bool}>}>}>
     */
    public function genres(bool $includeMature = false): array
    {
        return Cache::remember('taxonomy:genres:v1:'.($includeMature ? 'all' : 'public'), self::TTL, function () use ($includeMature): array {
            $genres = Genre::query()->visible($includeMature)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'slug', 'parent_id', 'group', 'sort', 'is_mature'])->keyBy('id');
            $items = [];

            foreach ($genres as $genre) {
                if ($genre->parent_id !== null && $genres->has($genre->parent_id)) {
                    $items[$genre->parent_id][] = ['genre' => $genre, 'group' => $genre->group, 'sort' => $genre->sort];
                }
            }

            foreach (DB::table('genre_secondary_parents')->orderBy('sort')->get() as $link) {
                if ($genres->has($link->genre_id) && $genres->has($link->parent_id)) {
                    $items[$link->parent_id][] = ['genre' => $genres->get($link->genre_id), 'group' => $link->group, 'sort' => (int) $link->sort];
                }
            }

            $tree = [];

            foreach ($genres->whereNull('parent_id') as $family) {
                $listed = collect($items[$family->id] ?? [])->sortBy('sort')->values();
                $groups = [];

                foreach ($listed as $item) {
                    $name = $item['group'];
                    $groups[$name ?? ''] ??= ['name' => $name, 'items' => []];
                    $groups[$name ?? '']['items'][] = ['slug' => $item['genre']->slug, 'name' => $item['genre']->name, 'is_mature' => $item['genre']->is_mature];
                }

                $tree[] = ['slug' => $family->slug, 'name' => $family->name, 'is_mature' => $family->is_mature, 'groups' => array_values($groups)];
            }

            return $tree;
        });
    }

    /**
     * @return array<string, string> theme slug to name, by name
     */
    public function themes(): array
    {
        return Cache::remember('taxonomy:themes:v1', self::TTL, fn (): array => Theme::query()->orderBy('name')->pluck('name', 'slug')->all());
    }

    /**
     * @return list<array{name: string|null, items: array<string, string>}> tropes by display group, in spec order
     */
    public function tropes(): array
    {
        return Cache::remember('taxonomy:tropes:v1', self::TTL, function (): array {
            $groups = [];

            foreach (Trope::query()->orderBy('sort')->get(['name', 'slug', 'group']) as $trope) {
                $groups[$trope->group ?? ''] ??= ['name' => $trope->group, 'items' => []];
                $groups[$trope->group ?? '']['items'][$trope->slug] = $trope->name;
            }

            return array_values($groups);
        });
    }

    /**
     * @return array<string, string> editorial demographic slug to name, in spec order
     */
    public function demographics(): array
    {
        return Cache::remember('taxonomy:demographics:v1', self::TTL, fn (): array => Demographic::query()->orderBy('sort')->pluck('name', 'slug')->all());
    }

    /**
     * @return array<string, string> content warning slug to name, in spec order
     */
    public function warnings(): array
    {
        return Cache::remember('taxonomy:warnings:v1', self::TTL, fn (): array => ContentWarning::query()->orderBy('sort')->pluck('name', 'slug')->all());
    }

    /**
     * The audience ratings a reader can filter by, as AgeRating value to label (youngest first).
     *
     * @return array<string, string>
     */
    public function ratings(): array
    {
        $ratings = [];

        foreach (AgeRating::filterable() as $rating) {
            $ratings[$rating->value] = (string) $rating->label();
        }

        return $ratings;
    }
}
