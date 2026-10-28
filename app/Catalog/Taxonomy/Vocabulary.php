<?php

namespace App\Catalog\Taxonomy;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * The seeded vocabulary, read from `database/data/taxonomy/vocabulary.csv`: a one to one transcription of the genre taxonomy spec
 * (`docs/superpowers/plans/genre-taxonomy-spec.md`) with one row per listing, `kind,family,group,name`.
 *
 * Rules that turn the listings into rows:
 *
 * - Genre families are the spec headings 1 to 16 and 19. A subgenre is a child of the family it is listed under; its `group` is the spec's display group.
 * - Slugs are the slugified names and unique across the whole tree. A subgenre listed under several families ("Courtroom Drama", "Techno-Thriller")
 *   is one genre: the first listing in spec order is its primary parent (`parent_id`), and every later one under a different family is an
 *   extra parent link (`genre_secondary_parents`). Selecting either family includes it; nothing is stored twice.
 * - A genre is `is_mature` only when its primary parent is the mature family (section 19). One listed first elsewhere ("Betrayal" under Drama,
 *   "Dark Romance" under Romance) stays visible in its own family; the mature family still lists it through its extra parent link while that family is visible.
 * - A name may appear once per facet: "Found Family" is a genre, a theme and a trope, three rows in three tables.
 */
final class Vocabulary
{
    /** The genre family that is mature-gated as a whole. */
    public const MATURE_FAMILY = 'mature-erotic-and-controversial-categories';

    /** @var list<array{kind: string, family: string, group: string, name: string}>|null */
    private static ?array $rows = null;

    /**
     * The slug of a vocabulary name. A slash separates words ("Grumpy/Sunshine" is `grumpy-sunshine`, not `grumpysunshine`).
     */
    public static function slug(string $name): string
    {
        return Str::slug(str_replace('/', ' ', $name));
    }

    public static function path(): string
    {
        return database_path('data/taxonomy/vocabulary.csv');
    }

    /**
     * Every listing, in spec order.
     *
     * @return list<array{kind: string, family: string, group: string, name: string}>
     */
    public static function rows(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }

        $handle = fopen(self::path(), 'r') ?: throw new RuntimeException('The taxonomy vocabulary file is missing.');
        fgetcsv($handle, escape: '');
        $rows = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            if (count($row) === 4) {
                $rows[] = ['kind' => $row[0], 'family' => $row[1], 'group' => $row[2], 'name' => $row[3]];
            }
        }

        fclose($handle);

        return self::$rows = $rows;
    }

    /**
     * The genre tree: the families, and each subgenre once with its primary family, display group, mature flag and extra parents.
     *
     * @return array{families: list<array{name: string, slug: string, sort: int, is_mature: bool}>, genres: list<array{name: string, slug: string, family: string, group: ?string, sort: int, is_mature: bool, also: list<array{family: string, group: ?string, sort: int}>}>}
     */
    public static function genres(): array
    {
        $families = [];
        $genres = [];
        $position = [];

        foreach (self::rows() as $row) {
            if ($row['kind'] !== 'genre') {
                continue;
            }

            $familySlug = self::slug($row['family']);
            $families[$familySlug] ??= ['name' => $row['family'], 'slug' => $familySlug, 'sort' => count($families) + 1, 'is_mature' => $familySlug === self::MATURE_FAMILY];
            $position[$familySlug] = ($position[$familySlug] ?? 0) + 1;

            $slug = self::slug($row['name']);
            $group = $row['group'] === '' ? null : $row['group'];
            $mature = $familySlug === self::MATURE_FAMILY;

            if (! isset($genres[$slug])) {
                $genres[$slug] = ['name' => $row['name'], 'slug' => $slug, 'family' => $familySlug, 'group' => $group, 'sort' => $position[$familySlug], 'is_mature' => $mature, 'also' => []];

                continue;
            }

            if ($genres[$slug]['family'] !== $familySlug && ! in_array($familySlug, array_column($genres[$slug]['also'], 'family'), true)) {
                $genres[$slug]['also'][] = ['family' => $familySlug, 'group' => $group, 'sort' => $position[$familySlug]];
            }
        }

        return ['families' => array_values($families), 'genres' => array_values($genres)];
    }

    /**
     * Themes, warnings and demographics: a name list in spec order.
     *
     * @return list<array{name: string, slug: string, sort: int}>
     */
    public static function plain(string $kind): array
    {
        $items = [];

        foreach (self::rows() as $row) {
            if ($row['kind'] === $kind) {
                $items[self::slug($row['name'])] ??= ['name' => $row['name'], 'slug' => self::slug($row['name']), 'sort' => count($items) + 1];
            }
        }

        return array_values($items);
    }

    /**
     * Tropes: a name list with the display group of the first listing.
     *
     * @return list<array{name: string, slug: string, group: ?string, sort: int}>
     */
    public static function tropes(): array
    {
        $items = [];

        foreach (self::rows() as $row) {
            if ($row['kind'] === 'trope') {
                $items[self::slug($row['name'])] ??= ['name' => $row['name'], 'slug' => self::slug($row['name']), 'group' => $row['group'] === '' ? null : $row['group'], 'sort' => count($items) + 1];
            }
        }

        return array_values($items);
    }

    /**
     * Every slug of a vocabulary kind (`genre`, `theme`, `trope`, `warning`, `demographic`), families included for genres.
     *
     * @return list<string>
     */
    public static function slugs(string $kind): array
    {
        return match ($kind) {
            'genre' => [...array_column(self::genres()['families'], 'slug'), ...array_column(self::genres()['genres'], 'slug')],
            'trope' => array_column(self::tropes(), 'slug'),
            default => array_column(self::plain($kind), 'slug'),
        };
    }
}
