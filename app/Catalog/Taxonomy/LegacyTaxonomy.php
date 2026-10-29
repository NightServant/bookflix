<?php

namespace App\Catalog\Taxonomy;

/**
 * Where the genre and theme slugs from before the taxonomy went (`database/data/taxonomy/legacy.csv`). The flat vocabulary was smaller and
 * coarser, so each retired slug points at the node that now carries its meaning: `action` is the Action and Adventure family, the
 * `school` theme is the School Life genre. When the new node is broader than the old term (`supernatural` is now paranormal fantasy),
 * `keep_tag` keeps the old term as a tag too, so nothing the old data said is lost.
 *
 * Old slugs that are also a node of the new vocabulary (`fantasy`, `war`...) keep working as they are and are not listed.
 */
final class LegacyTaxonomy
{
    /** @var array<string, array<string, array{kind: string, slug: string, keep_tag: bool}>>|null */
    private static ?array $map = null;

    /**
     * The retired slugs by old kind (genre or theme), then old slug.
     *
     * @return array<string, array<string, array{kind: string, slug: string, keep_tag: bool}>>
     */
    public static function map(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        $map = ['genre' => [], 'theme' => []];
        $lines = file(database_path('data/taxonomy/legacy.csv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach (array_slice($lines, 1) as $line) {
            [$kind, $old, $targetKind, $targetSlug, $keepTag] = str_getcsv($line, escape: '') + [null, null, null, null, null];

            if ($kind !== null && $old !== null && $targetKind !== null && $targetSlug !== null) {
                $map[$kind][$old] = ['kind' => $targetKind, 'slug' => $targetSlug, 'keep_tag' => $keepTag === 'yes'];
            }
        }

        return self::$map = $map;
    }

    /**
     * What a retired slug of the given old kind (genre or theme) is now, or null when it is not retired.
     *
     * @return array{kind: string, slug: string, keep_tag: bool}|null
     */
    public static function resolve(string $kind, string $slug): ?array
    {
        return self::map()[$kind][$slug] ?? null;
    }

    /**
     * The genre slug a genre slug stands for now: itself when it is current, the genre that replaced it when it is retired.
     * A retired genre that became a warning or tag stays as it is (it matches no genre).
     */
    public static function genreSlug(string $slug): string
    {
        $target = self::resolve('genre', $slug);

        return $target !== null && $target['kind'] === 'genre' ? $target['slug'] : $slug;
    }
}
