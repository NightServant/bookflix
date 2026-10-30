<?php

namespace App\Catalog\Ingestion;

use App\Actions\Library\RemoveFromCollection;
use App\Catalog\Classification\Reclassifier;
use App\Catalog\Data\NormalizedMedia;
use App\Enums\ClassificationType;
use App\Enums\DuplicateStatus;
use App\Enums\ReviewStatus;
use App\Enums\WarningStatus;
use App\Jobs\RecalculateMediaRating;
use App\Models\CollectionItem;
use App\Models\DuplicateCandidate;
use App\Models\ExternalSource;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use App\Models\MediaExternalId;
use App\Models\MediaType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class Deduplicator
{
    private const MAX_MERGE_HOPS = 20;

    /**
     * Pivot tables that follow a media row.
     *
     * @var list<string>
     */
    private const PIVOTS = ['media_creators', 'media_publishers', 'media_series', 'media_genres', 'media_themes', 'media_tropes', 'media_demographics', 'media_tags', 'media_characters'];

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
     * The form of the manga, manhwa or manhua this record is a translation or edition of: a near-identical title, a shared creator, an original no
     * younger than it, and an original from a provider that names the format (AniList, MangaDex, MangaUpdates). The pair stays a review candidate,
     * not a merge (D-21: a year gap never auto-merges), but the form is the work's in every language. `$except` is the record's own media,
     * which must not vouch for itself.
     */
    public function originalForm(NormalizedMedia $incoming, ?int $except = null): ?string
    {
        $formats = ExternalSource::query()->whereIn('key', Reclassifier::FORMAT_PROVIDERS)->pluck('id')->all();

        $original = $this->similar($incoming)->first(fn (array $hit): bool => $hit['media']->id !== $except
            && $hit['score'] >= (float) config('bookflix.dedup.auto_title_similarity')
            && in_array($hit['media']->mediaType->slug, ['manga', 'manhwa', 'manhua'], true)
            && in_array($hit['media']->primary_external_source_id, $formats, true)
            && ($incoming->publicationYear === null || $hit['media']->publication_year === null || $incoming->publicationYear >= $hit['media']->publication_year)
            && $this->sharesCreator($incoming, $hit['media']));

        return $original['media']->mediaType->slug ?? null;
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
     * Lock a media row and return the live media it stands for, or null when it was removed outright. A media that was
     * merged away meanwhile hands over to its winner (followed through chains), so work aimed at a stale match lands on the survivor.
     * Call inside a transaction; the returned row stays locked until it ends.
     */
    public function lockLive(Media $media): ?Media
    {
        $id = $media->id;

        for ($hops = 0; $hops < self::MAX_MERGE_HOPS && $id !== null; $hops++) {
            $row = Media::withTrashed()->lockForUpdate()->find($id);

            if ($row === null || ! $row->trashed()) {
                return $row;
            }

            $id = $row->merged_into_id;
        }

        return null;
    }

    /**
     * Fold the loser into the winner: external ids, Dewey classes, pivot rows and relations move over, the readers' data follows (see
     * moveUserData), the winner's search text is rebuilt, the pair being merged is closed and every other pending pair of the loser is
     * repointed at the winner, then the loser is soft deleted and points at the winner so its URL can still resolve (media already merged
     * into the loser follow along). Once the merge has committed the winner's reader rating is recalculated, since it now holds more scores.
     *
     * Both rows are locked and re-checked first: a loser that is already gone is a no-op, and a winner merged away meanwhile
     * is replaced by the media it went into.
     *
     * @throws RuntimeException when the winner was removed outright
     */
    public function merge(Media $winner, Media $loser, ?int $resolvedBy = null): void
    {
        DB::transaction(function () use ($winner, $loser, $resolvedBy): void {
            $locked = Media::withTrashed()->whereIn('id', [$winner->id, $loser->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $loser = $locked->get($loser->id);

            if ($loser === null || $loser->trashed()) {
                return;
            }

            $winner = $this->lockLive($winner) ?? throw new RuntimeException('The media to keep was removed.');

            if ($winner->is($loser)) {
                return;
            }

            $loser->externalIds()->update(['media_id' => $winner->id]);

            foreach (self::PIVOTS as $table) {
                $rows = DB::table($table)->where('media_id', $loser->id)->get()
                    ->map(fn (object $row): array => [...(array) $row, 'media_id' => $winner->id])
                    ->all();

                DB::table($table)->insertOrIgnore($rows);
                DB::table($table)->where('media_id', $loser->id)->delete();
            }

            $this->moveWarnings($winner, $loser);
            $this->moveRelations($winner, $loser);
            $this->moveClassifications($winner, $loser);
            $this->moveUserData($winner, $loser);
            $this->movePendingCandidates($winner, $loser, $resolvedBy);

            Media::withTrashed()->where('merged_into_id', $loser->id)->update(['merged_into_id' => $winner->id]);

            $winner->primary_external_source_id ??= $loser->primary_external_source_id;
            $winner->save();
            $loser->update(['merged_into_id' => $winner->id]);
            $loser->delete();
            $winner->refreshSearchText();

            RecalculateMediaRating::dispatch($winner->id)->afterCommit();
        });
    }

    /**
     * The loser's readers keep what they had. Library entries, ratings and reading progress are one row per reader and story: where the reader
     * already has a row for the winner it stays and the loser's is dropped, otherwise the row moves over. A dropped library row or progress row
     * first gives the winner's what it had that the winner's lacks (see carryOver), so a favorite or further progress is never lost to a merge.
     * Reading history and recorded signals are a log, so they all move. The loser's stored reader rating (the `bookflix` rows in `media_metrics`) is dropped with its ratings;
     * the winner's is rebuilt from `ratings` by the job merge queues. The media rows are locked, so no reader can add either story meanwhile.
     */
    private function moveUserData(Media $winner, Media $loser): void
    {
        $this->carryOver($winner, $loser);

        foreach (['user_library', 'ratings', 'reading_progress'] as $table) {
            DB::table($table)->where('media_id', $loser->id)
                ->whereIn('user_id', DB::table($table)->where('media_id', $winner->id)->select('user_id'))
                ->delete();
            DB::table($table)->where('media_id', $loser->id)->update(['media_id' => $winner->id]);
        }

        $this->moveCollectionItems($winner, $loser);

        DB::table('reading_history')->where('media_id', $loser->id)->update(['media_id' => $winner->id]);
        DB::table('user_interactions')->where('media_id', $loser->id)->update(['media_id' => $winner->id]);
        DB::table('media_metrics')->where('media_id', $loser->id)
            ->whereIn('external_source_id', ExternalSource::query()->where('key', ExternalSource::READERS)->select('id'))
            ->delete();
    }

    /**
     * For a reader who has both stories, before the loser's rows are dropped: the winner's library row takes the loser's favorite when it has none,
     * the earlier of the two added and started dates (a date only one of them has is kept) and the loser's completion when it has none of its own;
     * its status stays. The winner's progress row becomes the loser's place (percent, page, chapter, locator and source together) when the loser got
     * further, and takes the later of the two last read times either way. One statement for each table, whatever the number of readers.
     */
    private function carryOver(Media $winner, Media $loser): void
    {
        DB::update(<<<'SQL'
            update user_library as kept set
                favorited_at = coalesce(kept.favorited_at, dropped.favorited_at),
                created_at = least(kept.created_at, dropped.created_at),
                started_at = least(kept.started_at, dropped.started_at),
                completed_at = coalesce(kept.completed_at, dropped.completed_at)
            from user_library as dropped
            where kept.media_id = ? and dropped.media_id = ? and dropped.user_id = kept.user_id
            SQL, [$winner->id, $loser->id]);

        DB::update(<<<'SQL'
            update reading_progress as kept set
                percent = case when dropped.percent > kept.percent then dropped.percent else kept.percent end,
                page = case when dropped.percent > kept.percent then dropped.page else kept.page end,
                chapter = case when dropped.percent > kept.percent then dropped.chapter else kept.chapter end,
                locator = case when dropped.percent > kept.percent then dropped.locator else kept.locator end,
                content_source_id = case when dropped.percent > kept.percent then dropped.content_source_id else kept.content_source_id end,
                last_read_at = greatest(kept.last_read_at, dropped.last_read_at)
            from reading_progress as dropped
            where kept.media_id = ? and dropped.media_id = ? and dropped.user_id = kept.user_id
            SQL, [$winner->id, $loser->id]);
    }

    /**
     * In a collection that holds both stories the winner's item stays and the loser's is removed (through the action that closes the gap, so
     * the positions stay contiguous); in any other the loser's item takes the winner's place where it stood.
     */
    private function moveCollectionItems(Media $winner, Media $loser): void
    {
        $remove = app(RemoveFromCollection::class);

        CollectionItem::query()
            ->where('media_id', $loser->id)
            ->whereIn('collection_id', CollectionItem::query()->where('media_id', $winner->id)->select('collection_id'))
            ->with('collection')
            ->lazyById(100)
            ->each(fn (CollectionItem $item) => $remove->handle($item->collection, $item));

        DB::table('collection_items')->where('media_id', $loser->id)->update(['media_id' => $winner->id]);
    }

    /**
     * The loser's content warnings follow it to the winner. When both have a status for the same warning they are reconciled
     * (a repeat changes nothing, unknown gives way, present against absent is disputed), so a merge never drops a warning.
     */
    private function moveWarnings(Media $winner, Media $loser): void
    {
        $kept = DB::table('media_content_warnings')->where('media_id', $winner->id)->pluck('status', 'content_warning_id');

        foreach (DB::table('media_content_warnings')->where('media_id', $loser->id)->get() as $row) {
            $current = $kept->get($row->content_warning_id);

            if ($current === null) {
                DB::table('media_content_warnings')->insert([...(array) $row, 'media_id' => $winner->id]);

                continue;
            }

            DB::table('media_content_warnings')
                ->where('media_id', $winner->id)->where('content_warning_id', $row->content_warning_id)
                ->update(['status' => WarningStatus::from($current)->reconcile(WarningStatus::from($row->status))->value]);
        }

        DB::table('media_content_warnings')->where('media_id', $loser->id)->delete();
    }

    /**
     * The loser's classes follow it to the winner. The winner's row wins a clash on the same class, and the winner never ends up with a second live primary:
     * the loser's primary becomes a secondary when the winner already has one, and any live row beyond the secondary limit is dropped.
     * Rejected rows move as they are, so the decision to reject still holds.
     */
    private function moveClassifications(Media $winner, Media $loser): void
    {
        $kept = MediaDeweyClass::where('media_id', $winner->id)->get();
        $live = fn (MediaDeweyClass $row): bool => $row->review_status !== ReviewStatus::Rejected;
        $hasPrimary = $kept->contains(fn (MediaDeweyClass $row): bool => $live($row) && $row->classification_type === ClassificationType::Primary);
        $secondaries = $kept->filter(fn (MediaDeweyClass $row): bool => $live($row) && $row->classification_type === ClassificationType::Secondary)->count();

        $incoming = MediaDeweyClass::where('media_id', $loser->id)->orderBy('id')->get()
            ->sortBy(fn (MediaDeweyClass $row): int => $row->classification_type === ClassificationType::Primary ? 0 : 1);

        foreach ($incoming as $row) {
            $type = $row->classification_type;
            $isLive = $live($row);

            if ($kept->contains('dewey_class_id', $row->dewey_class_id)) {
                $row->delete();

                continue;
            }

            if ($isLive && $type === ClassificationType::Primary && $hasPrimary) {
                $type = ClassificationType::Secondary;
            }

            if ($isLive && $type === ClassificationType::Secondary && $secondaries >= config('bookflix.classification.max_secondary')) {
                $row->delete();

                continue;
            }

            $row->update(['media_id' => $winner->id, 'classification_type' => $type]);

            $hasPrimary = $hasPrimary || ($isLive && $type === ClassificationType::Primary);
            $secondaries += $isLive && $type === ClassificationType::Secondary ? 1 : 0;
        }
    }

    /**
     * The pair being merged is closed. Every other pending pair of the loser is still unreviewed, so it moves to the
     * winner and stays pending; one the winner already has with that media is dropped, not doubled.
     */
    private function movePendingCandidates(Media $winner, Media $loser, ?int $resolvedBy): void
    {
        $pending = DuplicateCandidate::query()
            ->where('status', DuplicateStatus::Pending)
            ->where(fn ($query) => $query->where('media_id', $loser->id)->orWhere('duplicate_media_id', $loser->id))
            ->get();

        foreach ($pending as $pair) {
            $other = $pair->media_id === $loser->id ? $pair->duplicate_media_id : $pair->media_id;

            if ($other === $winner->id) {
                $pair->update(['status' => DuplicateStatus::Merged, 'resolved_by' => $resolvedBy]);

                continue;
            }

            $moved = ['media_id' => min($winner->id, $other), 'duplicate_media_id' => max($winner->id, $other)];

            if (DuplicateCandidate::where($moved)->exists()) {
                $pair->delete();
            } else {
                $pair->update($moved);
            }
        }
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
            ->with(['creators', 'mediaType'])
            ->get()
            ->map(function (Media $media) use ($incoming): array {
                $score = round((float) $media->getAttribute('title_score'), 4);

                return [
                    'media' => $media,
                    'score' => $score,
                    'auto' => $this->qualifiesForAutoMerge($incoming, $media, $score),
                ];
            });
    }

    /**
     * A near-identical title with a shared creator and a year within the window, or an identical normalized title in the
     * same family published in exactly the same year (providers disagree on creator spelling, or send none).
     */
    private function qualifiesForAutoMerge(NormalizedMedia $incoming, Media $media, float $score): bool
    {
        if ($score >= (float) config('bookflix.dedup.auto_title_similarity')
            && $this->sharesCreator($incoming, $media)
            && $this->withinYearWindow($incoming, $media)) {
            return true;
        }

        return $score >= 1.0
            && $incoming->publicationYear !== null
            && $incoming->publicationYear === $media->publication_year;
    }

    /**
     * Whether a credited name appears on both. Providers write the same person differently: MangaUpdates adds the name in its own script and puts
     * the family name first ("Oda Eiichirou (尾田栄一郎)"), AniList writes long vowels out ("Katsuhiro Ootomo"), Comic Vine closes up pen names
     * ("singNsong"). So the script part is dropped, long vowels folded, and a name matches in its own word order or with its words sorted.
     */
    private function sharesCreator(NormalizedMedia $incoming, Media $media): bool
    {
        $names = fn (iterable $names): array => collect($names)
            ->flatMap(function (string $name): array {
                $folded = str_replace(['ou', 'oo', 'uu'], ['o', 'o', 'u'], Str::lower(Str::ascii(preg_replace('/\s*\([^)]*\)\s*$/u', '', $name) ?? $name)));
                $words = preg_split('/[^a-z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $sorted = $words;
                sort($sorted);

                return $words === [] ? [] : [implode('', $words), implode('', $sorted)];
            })
            ->all();

        return array_intersect(
            $names([...array_map(fn ($creator): string => $creator->name, $incoming->creators), ...$incoming->credits]),
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
