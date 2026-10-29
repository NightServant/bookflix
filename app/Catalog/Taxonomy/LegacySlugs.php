<?php

namespace App\Catalog\Taxonomy;

use App\Models\Genre;
use App\Models\Theme;
use Illuminate\Container\Attributes\Scoped;

/**
 * Upgrades genre and theme slugs from before the taxonomy (a bookmarked `?filters[genres][]=action`) to the nodes that replaced them (see LegacyTaxonomy),
 * so old links keep working. A retired slug is only rewritten once it is really gone from the table: a database that still has a genre called `action`
 * keeps it. The check is one query per kind, made only when a retired slug turns up, and remembered for the request.
 */
#[Scoped]
class LegacySlugs
{
    /** @var array<string, bool> Whether `kind:slug` is still a row. */
    private array $live = [];

    /**
     * Filter slugs rewritten to the nodes that replaced them, as `[facet => slugs]`: a retired genre slug is the genre that replaced it, a retired theme slug the
     * genre (or theme) that replaced it. A slug that points at a warning or a tag has no filter to move to, so it stays and matches nothing rather than silently
     * widening the search.
     *
     * @param  array{genres: list<string>, themes: list<string>}  $filters
     * @return array{genres: list<string>, themes: list<string>}
     */
    public function upgrade(array $filters): array
    {
        $this->lookUp($filters);
        $upgraded = ['genres' => [], 'themes' => []];

        foreach (['genre' => 'genres', 'theme' => 'themes'] as $kind => $facet) {
            foreach ($filters[$facet] as $slug) {
                $gone = isset($this->live["{$kind}:{$slug}"]) && ! $this->live["{$kind}:{$slug}"];
                $target = $gone ? LegacyTaxonomy::resolve($kind, $slug) : null;

                match (true) {
                    $target === null => $upgraded[$facet][] = $slug,
                    $target['kind'] === 'genre' => $upgraded['genres'][] = $target['slug'],
                    $target['kind'] === 'theme' => $upgraded['themes'][] = $target['slug'],
                    default => $upgraded[$facet][] = $slug,
                };
            }
        }

        return ['genres' => array_values(array_unique($upgraded['genres'])), 'themes' => array_values(array_unique($upgraded['themes']))];
    }

    /**
     * @param  array{genres: list<string>, themes: list<string>}  $filters
     */
    private function lookUp(array $filters): void
    {
        foreach (['genre' => [Genre::class, 'genres'], 'theme' => [Theme::class, 'themes']] as $kind => [$model, $facet]) {
            $retired = array_values(array_filter(
                array_unique($filters[$facet]),
                fn (string $slug): bool => LegacyTaxonomy::resolve($kind, $slug) !== null && ! isset($this->live["{$kind}:{$slug}"]),
            ));

            if ($retired === []) {
                continue;
            }

            $alive = $model::query()->whereIn('slug', $retired)->pluck('slug')->all();

            foreach ($retired as $slug) {
                $this->live["{$kind}:{$slug}"] = in_array($slug, $alive, true);
            }
        }
    }
}
