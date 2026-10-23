<?php

namespace App\Catalog\Ingestion;

use App\Catalog\Data\NormalizedMedia;
use App\Models\DuplicateCandidate;
use App\Models\Media;
use App\Models\MediaExternalId;
use App\Models\MediaType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Deduplicator
{
    /**
     * Pivot tables that follow a media row.
     *
     * @var list<string>
     */
    private const PIVOTS = ['media_creators', 'media_publishers', 'media_series', 'media_genres', 'media_themes', 'media_tags', 'media_characters'];

    /**
     * Trigram similarity between a stored title and the bound incoming one, both lowercased, unaccented,
     * stripped of punctuation and single-spaced. Binds the incoming title once.
     */
    private const SIMILARITY = "similarity(trim(regexp_replace(regexp_replace(unaccent(lower(media.title)), '[[:punct:]]', ' ', 'g'), '\\s+', ' ', 'g')), trim(regexp_replace(regexp_replace(unaccent(lower(cast(? as text))), '[[:punct:]]', ' ', 'g'), '\\s+', ' ', 'g')))";

    /**
     * The existing media this record is the same work as, or null.
     *
     * Auto-merge bands (A9): an ISBN-13 or cross id already on a media, or a near-identical title
     * (same family) that also shares a creator and has a year within the window.
     */
    public function match(NormalizedMedia $incoming): ?Media
    {
        return $this->matchByExternalId($incoming)
            ?? $this->similar($incoming)->firstWhere('auto', true)['media']
            ?? null;
    }

    /**
     * Same-family media with a similar title that did not qualify for auto-merge, best first.
     *
     * @return Collection<int, array{media: Media, score: float}>
     */
    public function candidates(NormalizedMedia $incoming): Collection
    {
        return $this->similar($incoming)
            ->where('auto', false)
            ->map(fn (array $hit): array => ['media' => $hit['media'], 'score' => $hit['score']])
            ->values();
    }

    /**
     * Record review candidates for a media. Pairs are stored once whichever side is passed first, and re-recording only refreshes the score.
     *
     * @param  Collection<int, array{media: Media, score: float}>  $candidates
     */
    public function recordCandidates(Media $media, Collection $candidates): void
    {
        DuplicateCandidate::upsert(
            $candidates->map(fn (array $candidate): array => [
                'media_id' => min($media->id, $candidate['media']->id),
                'duplicate_media_id' => max($media->id, $candidate['media']->id),
                'score' => $candidate['score'],
            ])->all(),
            ['media_id', 'duplicate_media_id'],
            ['score'],
        );
    }

    /**
     * Fold the loser into the winner: external ids, pivot rows and relations move over, then the loser is soft deleted
     * and points at the winner so its URL can still resolve.
     */
    public function merge(Media $winner, Media $loser): void
    {
        DB::transaction(function () use ($winner, $loser): void {
            $loser->externalIds()->update(['media_id' => $winner->id]);

            foreach (self::PIVOTS as $table) {
                $rows = DB::table($table)->where('media_id', $loser->id)->get()
                    ->map(fn (object $row): array => [...(array) $row, 'media_id' => $winner->id])
                    ->all();

                DB::table($table)->insertOrIgnore($rows);
                DB::table($table)->where('media_id', $loser->id)->delete();
            }

            $this->moveRelations($winner, $loser);

            $loser->update(['merged_into_id' => $winner->id]);
            $loser->delete();
        });
    }

    private function matchByExternalId(NormalizedMedia $incoming): ?Media
    {
        $pairs = [
            ...array_map(fn (string $isbn): array => ['isbn', $isbn], $incoming->isbns),
            ...array_map(fn (string $id, string $source): array => [$source, $id], $incoming->crossIds, array_keys($incoming->crossIds)),
        ];

        if ($pairs === []) {
            return null;
        }

        $ids = MediaExternalId::query()
            ->select('media_external_ids.media_id')
            ->join('external_sources', 'external_sources.id', '=', 'media_external_ids.external_source_id')
            ->where(function ($query) use ($pairs): void {
                foreach ($pairs as [$source, $id]) {
                    $query->orWhere(fn ($pair) => $pair->where('external_sources.key', $source)->where('media_external_ids.external_id', $id));
                }
            });

        return Media::query()->whereIn('id', $ids)->first();
    }

    /**
     * Same-family media with a title similarity from the review floor up, best first.
     *
     * ponytail: sequential scan over the family's media; add an immutable-unaccent trigram expression index once the catalogue passes ~50k rows.
     *
     * @return Collection<int, array{media: Media, score: float, auto: bool}>
     */
    private function similar(NormalizedMedia $incoming): Collection
    {
        $families = MediaType::query()->where('slug', $incoming->mediaTypeSlug)->select('family');
        $types = MediaType::query()->whereIn('family', $families)->select('id');

        return Media::query()
            ->select('media.*')
            ->selectRaw(self::SIMILARITY.' as title_score', [$incoming->title])
            ->whereIn('media_type_id', $types)
            ->whereRaw(self::SIMILARITY.' >= cast(? as real)', [$incoming->title, (float) config('bookflix.dedup.review_min_similarity')])
            ->orderByDesc('title_score')
            ->limit(25)
            ->with('creators')
            ->get()
            ->map(function (Media $media) use ($incoming): array {
                $score = round((float) $media->getAttribute('title_score'), 4);

                return [
                    'media' => $media,
                    'score' => $score,
                    'auto' => $score >= (float) config('bookflix.dedup.auto_title_similarity')
                        && $this->sharesCreator($incoming, $media)
                        && $this->withinYearWindow($incoming, $media),
                ];
            });
    }

    private function sharesCreator(NormalizedMedia $incoming, Media $media): bool
    {
        $names = fn (iterable $names): array => collect($names)
            ->map(fn (string $name): string => trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', Str::lower(Str::ascii($name)))))
            ->filter()
            ->all();

        return array_intersect(
            $names(array_map(fn ($creator): string => $creator->name, $incoming->creators)),
            $names($media->creators->pluck('name')),
        ) !== [];
    }

    private function withinYearWindow(NormalizedMedia $incoming, Media $media): bool
    {
        return $incoming->publicationYear !== null
            && $media->publication_year !== null
            && abs($incoming->publicationYear - $media->publication_year) <= (int) config('bookflix.dedup.year_window');
    }

    /**
     * Repoint relations in both directions, dropping any that would now relate a media to itself.
     */
    private function moveRelations(Media $winner, Media $loser): void
    {
        $swap = fn (int $id): int => $id === $loser->id ? $winner->id : $id;

        $rows = DB::table('media_relations')->where('media_id', $loser->id)->orWhere('related_media_id', $loser->id)->get()
            ->map(fn (object $row): array => [...(array) $row, 'media_id' => $swap($row->media_id), 'related_media_id' => $swap($row->related_media_id)])
            ->reject(fn (array $row): bool => $row['media_id'] === $row['related_media_id'])
            ->all();

        DB::table('media_relations')->insertOrIgnore($rows);
        DB::table('media_relations')->where('media_id', $loser->id)->orWhere('related_media_id', $loser->id)->delete();
    }
}
