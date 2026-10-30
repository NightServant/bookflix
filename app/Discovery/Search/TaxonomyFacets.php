<?php

namespace App\Discovery\Search;

use App\Enums\AgeRating;
use App\Enums\PublicationStatus;
use App\Models\Genre;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The taxonomy as plain arrays for a filter UI, kept ten minutes: the nested genre tree (with live story counts), and the flat lists of media types,
 * themes, tropes, demographics, audience ratings and content warnings. Nothing returned here is a model, so it caches and serializes without surprises.
 *
 * What a gated reader is offered is decided by the caller from their access (`$includeMature`, see MatureAccess): the mature genres, the adult
 * rating and the story counts that include gated stories are left out unless it is true. A filter never decides it.
 */
class TaxonomyFacets
{
    private const TTL = 600;

    /** Every cache key this class and the pages built on the taxonomy use, so seeding can drop them together. */
    public const KEYS = [
        'taxonomy:genres:v2:public', 'taxonomy:genres:v2:all', 'taxonomy:flat:v1',
        'discover:genre-counts:v3:gated', 'discover:genre-counts:v3:mature',
    ];

    public static function flush(): void
    {
        foreach (self::KEYS as $key) {
            Cache::forget($key);
        }
    }

    /**
     * The genre families in spec order, each with its subgenres in display groups (the group is null for a family with no groups) and live story counts:
     * a family's `count` is every story in its tree, a subgenre's the stories tagged with it (0 when none). A subgenre listed under two families
     * appears under both. Mature-gated genres, and the gated stories in the counts, are left out unless asked for; asking is the caller's decision to
     * make from the reader's access, never from a filter.
     *
     * @return list<array{slug: string, name: string, is_mature: bool, count: int, groups: list<array{name: string|null, items: list<array{slug: string, name: string, is_mature: bool, count: int}>}>}>
     */
    public function genres(bool $includeMature = false): array
    {
        return Cache::remember('taxonomy:genres:v2:'.($includeMature ? 'all' : 'public'), self::TTL, function () use ($includeMature): array {
            $genres = Genre::query()->visible($includeMature)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'slug', 'parent_id', 'group', 'sort', 'is_mature'])->keyBy('id');
            ['families' => $familyCounts, 'tags' => $tagCounts] = Genre::counts($includeMature);
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
                    $groups[$name ?? '']['items'][] = ['slug' => $item['genre']->slug, 'name' => $item['genre']->name, 'is_mature' => $item['genre']->is_mature, 'count' => $tagCounts[$item['genre']->id] ?? 0];
                }

                $tree[] = ['slug' => $family->slug, 'name' => $family->name, 'is_mature' => $family->is_mature, 'count' => $familyCounts[$family->id] ?? 0, 'groups' => array_values($groups)];
            }

            return $tree;
        });
    }

    /**
     * The five small facets in one query, kept ten minutes: media types and themes by name, tropes, editorial demographics and content warnings in spec
     * order. A cold page pays for one statement, not five.
     *
     * @return array{types: array<string, string>, themes: array<string, string>, tropes: list<array{name: string|null, items: array<string, string>}>, demographics: array<string, string>, warnings: array<string, string>}
     */
    private function flat(): array
    {
        return Cache::remember('taxonomy:flat:v1', self::TTL, function (): array {
            $rows = DB::query()->fromSub(
                DB::table('media_types')->selectRaw("'type' as kind, slug, name, cast(null as text) as grp, 0 as sort")
                    ->unionAll(DB::table('themes')->selectRaw("'theme', slug, name, cast(null as text), 0"))
                    ->unionAll(DB::table('tropes')->selectRaw("'trope', slug, name, \"group\", sort"))
                    ->unionAll(DB::table('demographics')->selectRaw("'demographic', slug, name, cast(null as text), sort"))
                    ->unionAll(DB::table('content_warnings')->selectRaw("'warning', slug, name, cast(null as text), sort")),
                'flat',
            )->orderBy('kind')->orderBy('sort')->orderBy('name')->get();

            $flat = ['types' => [], 'themes' => [], 'tropes' => [], 'demographics' => [], 'warnings' => []];

            foreach ($rows as $row) {
                ['kind' => $kind, 'slug' => $slug, 'name' => $name, 'grp' => $group] = (array) $row;

                match ($kind) {
                    'type' => $flat['types'][$slug] = $name,
                    'theme' => $flat['themes'][$slug] = $name,
                    'demographic' => $flat['demographics'][$slug] = $name,
                    'warning' => $flat['warnings'][$slug] = $name,
                    default => $flat['tropes'][$group ?? ''] = ['name' => $group, 'items' => [...($flat['tropes'][$group ?? '']['items'] ?? []), $slug => $name]],
                };
            }

            $flat['tropes'] = array_values($flat['tropes']);

            return $flat;
        });
    }

    /**
     * @return array<string, string> media type slug to name, by name
     */
    public function types(): array
    {
        return $this->flat()['types'];
    }

    /**
     * @return array<string, string> theme slug to name, by name
     */
    public function themes(): array
    {
        return $this->flat()['themes'];
    }

    /**
     * @return list<array{name: string|null, items: array<string, string>}> tropes by display group, in spec order
     */
    public function tropes(): array
    {
        return $this->flat()['tropes'];
    }

    /**
     * @return array<string, string> editorial demographic slug to name, in spec order
     */
    public function demographics(): array
    {
        return $this->flat()['demographics'];
    }

    /**
     * @return array<string, string> content warning slug to name, in spec order
     */
    public function warnings(): array
    {
        return $this->flat()['warnings'];
    }

    /**
     * The audience ratings a reader can filter by, as AgeRating value to label (youngest first). Adult (18+) is offered only to a reader who
     * may see mature and adult titles: for anyone else the option would find nothing, and offering it would say there is something to find.
     *
     * @return array<string, string>
     */
    public function ratings(bool $includeMature = false): array
    {
        $ratings = [];

        foreach (AgeRating::filterable() as $rating) {
            if ($rating === AgeRating::Adult && ! $includeMature) {
                continue;
            }

            $ratings[$rating->value] = (string) $rating->label();
        }

        return $ratings;
    }

    /**
     * @return array<string, string> publication status value to label
     */
    public function statuses(): array
    {
        $statuses = [];

        foreach (PublicationStatus::cases() as $status) {
            if ($status !== PublicationStatus::Unknown) {
                $statuses[$status->value] = (string) __(ucfirst($status->value));
            }
        }

        return $statuses;
    }

    /**
     * @return array<string, string> ISO 639-1 code to language name
     */
    public function languages(): array
    {
        $languages = [];

        foreach ((array) config('bookflix.languages') as $code) {
            $code = (string) $code;
            $languages[$code] = class_exists(\Locale::class) ? ucfirst(\Locale::getDisplayLanguage($code, app()->getLocale()) ?: strtoupper($code)) : strtoupper($code);
        }

        return $languages;
    }

    /**
     * @return array<string, string> length bucket to label
     */
    public function lengths(): array
    {
        return ['short' => (string) __('Short'), 'medium' => (string) __('Medium'), 'long' => (string) __('Long')];
    }

    /**
     * @return array<int|string, string> least average rating, in stars on the 0 to 5 scale of `media.average_rating` (see SearchFilters::starsKey), to label
     */
    public function minRatings(): array
    {
        return ['3' => (string) __('3+ stars'), '3.5' => (string) __('3.5+ stars'), '4' => (string) __('4+ stars'), '4.5' => (string) __('4.5+ stars')];
    }

    /**
     * The label of every value of every filter group, as `group => [slug => label]`: what the chips above the results are named from. Genres are
     * every family and subgenre the reader may be offered, flattened.
     *
     * @return array{mediaTypes: array<string, string>, statuses: array<string, string>, genres: array<string, string>, themes: array<string, string>, tropes: array<string, string>, demographics: array<string, string>, ratings: array<string, string>, excludeWarnings: array<string, string>, languages: array<string, string>, lengths: array<string, string>, minRatings: array<int|string, string>}
     */
    public function labels(bool $includeMature = false): array
    {
        $genres = [];

        foreach ($this->genres($includeMature) as $family) {
            $genres[$family['slug']] = $family['name'];

            foreach ($family['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    $genres[$item['slug']] ??= $item['name'];
                }
            }
        }

        return [
            'mediaTypes' => $this->types(),
            'statuses' => $this->statuses(),
            'genres' => $genres,
            'themes' => $this->themes(),
            'tropes' => collect($this->tropes())->flatMap(fn (array $group): array => $group['items'])->all(),
            'demographics' => $this->demographics(),
            'ratings' => $this->ratings($includeMature),
            'excludeWarnings' => $this->warnings(),
            'languages' => $this->languages(),
            'lengths' => $this->lengths(),
            'minRatings' => $this->minRatings(),
        ];
    }

    /**
     * Everything the filter panel renders, as one plain structure for the page's single JSON data island (see the `taxonomy-filters` component).
     * The panel is built from this client-side, once for the sidebar and the sheet alike, so the tree is never serialized per copy or per update.
     *
     * @return array<string, mixed>
     */
    public function panel(bool $includeMature = false): array
    {
        $options = fn (array $labels): array => array_map(fn (int|string $value, string $label): array => ['value' => (string) $value, 'label' => $label], array_keys($labels), array_values($labels));

        return [
            'types' => $options($this->types()),
            'genres' => $this->genres($includeMature),
            'themes' => $options($this->themes()),
            'tropes' => array_map(fn (array $group): array => ['name' => $group['name'], 'items' => $options($group['items'])], $this->tropes()),
            'demographics' => $options($this->demographics()),
            'ratings' => $options($this->ratings($includeMature)),
            'warnings' => $options($this->warnings()),
            'statuses' => $options($this->statuses()),
            'languages' => $options($this->languages()),
            'lengths' => $options($this->lengths()),
            'minRatings' => $options($this->minRatings()),
        ];
    }
}
