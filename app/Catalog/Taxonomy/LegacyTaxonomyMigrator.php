<?php

namespace App\Catalog\Taxonomy;

use App\Catalog\Ingestion\TaxonomyMapper;
use App\Enums\WarningStatus;
use App\Models\Media;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moves what the flat taxonomy stored onto the new nodes, without losing a row of data:
 *
 * - media tagged with a retired genre or theme slug (legacy.csv) are tagged with the node that replaced it, then the retired row goes;
 * - the old `media.content_warnings` names become `present` warnings (the column itself is left in place and readable).
 *
 * Idempotent: a retired row that is gone is skipped, and warnings are reconciled, not duplicated. The target nodes must be seeded first.
 */
class LegacyTaxonomyMigrator
{
    /** @var array<string, array{table: string, pivot: string, key: string}> */
    private const FACETS = [
        'genre' => ['table' => 'genres', 'pivot' => 'media_genres', 'key' => 'genre_id'],
        'theme' => ['table' => 'themes', 'pivot' => 'media_themes', 'key' => 'theme_id'],
        'warning' => ['table' => 'content_warnings', 'pivot' => 'media_content_warnings', 'key' => 'content_warning_id'],
    ];

    public function __construct(private TaxonomyMapper $mapper, private WarningRecorder $warnings) {}

    /**
     * @return array{retired: int, media_moved: int, warnings: int} How many retired rows went, how many media pivot rows moved, how many old warnings were recorded.
     */
    public function run(): array
    {
        $retired = 0;
        $moved = 0;

        foreach (LegacyTaxonomy::map() as $kind => $slugs) {
            foreach ($slugs as $old => $target) {
                $count = $this->retire($kind, $old, $target);

                if ($count !== null) {
                    $retired++;
                    $moved += $count;
                }
            }
        }

        return ['retired' => $retired, 'media_moved' => $moved, 'warnings' => $this->contentWarnings()];
    }

    /**
     * @param  array{kind: string, slug: string, keep_tag: bool}  $target
     * @return int|null How many media were moved, or null when there was nothing to retire.
     */
    private function retire(string $kind, string $oldSlug, array $target): ?int
    {
        $from = self::FACETS[$kind];
        $to = self::FACETS[$target['kind']];

        if (in_array($oldSlug, Vocabulary::slugs($kind), true)) {
            return null; // still a live node of the vocabulary
        }

        $old = DB::table($from['table'])->where('slug', $oldSlug)->first();
        $new = DB::table($to['table'])->where('slug', $target['slug'])->first();

        if ($old === null || $new === null) {
            return null;
        }

        return DB::transaction(function () use ($from, $to, $old, $new, $target): int {
            $extra = $target['kind'] === 'warning' ? ", 'present', 'legacy'" : '';
            $columns = $target['kind'] === 'warning' ? ', status, source' : '';

            $moved = DB::affectingStatement(
                "insert into {$to['pivot']} (media_id, {$to['key']}{$columns}, created_at, updated_at) select media_id, ?{$extra}, created_at, updated_at from {$from['pivot']} where {$from['key']} = ? on conflict do nothing",
                [$new->id, $old->id],
            );

            if ($target['keep_tag']) {
                $tag = Tag::firstOrCreate(['slug' => $old->slug], ['name' => $old->name]);

                DB::affectingStatement(
                    "insert into media_tags (media_id, tag_id, created_at, updated_at) select media_id, ?, created_at, updated_at from {$from['pivot']} where {$from['key']} = ? on conflict do nothing",
                    [$tag->id, $old->id],
                );
            }

            DB::table($from['pivot'])->where($from['key'], $old->id)->delete();
            DB::table($from['table'])->where('id', $old->id)->delete();

            return $moved;
        });
    }

    /**
     * The old per-media list of warning names, as present warnings. A name the vocabulary has no warning for becomes a tag, so it still shows.
     */
    private function contentWarnings(): int
    {
        $recorded = 0;

        Media::query()->whereRaw("content_warnings <> '[]'::jsonb")->each(function (Media $media) use (&$recorded): void {
            $mapped = $this->mapper->mapTerms('hardcover', [], array_values(array_filter($media->content_warnings, 'is_string')));

            $recorded += $this->warnings->record($media, $mapped->warnings, WarningStatus::Present, 'legacy', respectLock: false);

            foreach ($mapped->tags as $slug => $name) {
                $media->tags()->syncWithoutDetaching([Tag::firstOrCreate(['slug' => $slug], ['name' => Str::limit($name, 255, '')])->id]);
            }
        });

        return $recorded;
    }
}
