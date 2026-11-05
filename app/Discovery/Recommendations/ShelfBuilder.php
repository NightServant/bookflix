<?php

namespace App\Discovery\Recommendations;

use App\Access\MatureAccess;
use App\Discovery\ContinueReading;
use App\Discovery\EditorialCollections;
use App\Discovery\HomeShelves;
use App\Discovery\Recommendations\Sources\ContentSimilarSource;
use App\Discovery\Shelf;
use App\Enums\ReasonType;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\ModelVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Random\Randomizer;

/**
 * The shelves of Home for one reader, in the order of the master plan (A12): Continue Reading, Recommended For You, Because You Read X, New
 * Releases, Similar To Y, Trending Now, Popular in Your Genres, Discover Something New, the editorial collections, Explore by Subject.
 * A shelf with nothing in it is left out. A reader with too few strong interactions (cold start), or who opted out, gets no Recommended,
 * Because You Read or Similar To shelf; the rest fill from trending, new releases, editorial and the preference-filtered popularity.
 *
 * The shelves built from candidates are cached ten minutes per reader and access level as lists of ids (with the recommendation id and label
 * of each), then loaded fresh through the mature gate, so a title that became gated, merged or deleted drops out of a cached shelf. They are
 * logged once per fill. Continue Reading, the collections and the subjects are read live, as Home reads them today.
 *
 * A title is shown on one candidate shelf only, the first in the order above that has it; the later shelf backfills from its next candidates, and
 * one left with fewer than `min_shelf_size` titles is left out (Trending, which is never empty, excepted). The log is of what is shown.
 *
 * Each title on a built shelf carries two extra attributes for the tile: `recommendation_id` (null when nothing was logged) and `reason_label`.
 */
class ShelfBuilder
{
    /** The most titles on a shelf. */
    private const PER_SHELF = 20;

    /** The most candidates a shelf considers, so that it can backfill the titles an earlier shelf took; Trending, which must never end up empty, considers all it was given. */
    private const BACKFILL = 60;

    /** The most titles asked of each source; Recommended For You ranks from the whole pool. */
    private const SOURCE_LIMIT = 100;

    /** The shelves made from candidates, in order. */
    private const CANDIDATE_SHELVES = ['recommended', 'because-you-read', 'new-releases', 'similar-to', 'trending', 'popular-in-genres', 'discover-new'];

    public function __construct(
        private CandidatePool $pool,
        private SeedItems $seeds,
        private HybridRanker $ranker,
        private Diversifier $diversifier,
        private ExplorationInjector $injector,
        private Randomizer $random,
        private ContentSimilarSource $content,
        private RecommendationLogger $logger,
        private ContinueReading $continueReading,
        private EditorialCollections $editorial,
        private HomeShelves $home,
    ) {}

    /**
     * @return Collection<int, Shelf>
     */
    public function forHome(User $user): Collection
    {
        $built = $this->recommendationShelves($user)->keyBy('key');
        $shelves = new Collection;

        $add = function (?Shelf $shelf) use ($shelves): void {
            if ($shelf !== null && $shelf->items->isNotEmpty()) {
                $shelves->push($shelf);
            }
        };

        $add($this->continueReading->shelf($user));

        foreach (self::CANDIDATE_SHELVES as $key) {
            $add($built->get($key));
        }

        foreach ($this->editorial->shelves($user) as $collection) {
            $add($collection);
        }

        $add($this->home->shelf('explore-by-subject', viewer: $user));

        return $shelves->values();
    }

    /**
     * Whether Home treats this reader as new: they opted out, or have fewer strong interactions than `cold_start_min_interactions`. The page shows the
     * getting-started card for a cold reader who has personalization on, and moves the staff picks up for any cold one.
     */
    public function isCold(User $user): bool
    {
        return ! $user->personalizationEnabled()
            || $this->seeds->for($user)->count() < (int) config('bookflix.recommendations.cold_start_min_interactions');
    }

    /**
     * The candidate shelves from the cache or, on a miss, freshly built and logged.
     *
     * @return Collection<int, Shelf>
     */
    private function recommendationShelves(User $user): Collection
    {
        $key = 'home:shelves:'.$user->id.':'.MatureAccess::cacheKey($user);
        $digest = Cache::get($key);

        if (is_array($digest)) {
            return $this->hydrate($user, $digest);
        }

        [$shelves, $reasons] = $this->seeds->during(fn (): array => $this->build($user));

        Cache::put($key, $this->digest($shelves, $reasons), now()->addMinutes((int) config('bookflix.recommendations.cache_minutes')));

        return $shelves;
    }

    /**
     * @return array{0: Collection<int, Shelf>, 1: array<string, int>} the shelves, and the id of the title each shelf's heading names
     */
    private function build(User $user): array
    {
        $enabled = $user->personalizationEnabled();
        $personal = ! $this->isCold($user);

        $lists = $this->pool->bySource($user, self::SOURCE_LIMIT);
        $pooled = $this->pool->combine($user, $lists);

        // Ranking gives every title its features and drops what the reader may not see; an opted-out reader's history is not read at all.
        $ranked = $enabled ? $this->ranker->rank($user, $pooled, RankingWeights::active())->keyBy('mediaId') : null;

        /** @var array<string, array{title: string, featured: bool, items: list<ServedItem>, reason?: int, href?: string|null}> $specs */
        $specs = [];

        // The shelves around one title list its neighbors, which the shared pool may have cut off: those are ranked too, in one more call.
        $recent = $personal ? $this->seeds->mostRecent($user) : null;
        $recent = $recent !== null && ! $recent->isGatedFor($user) ? $recent : null;
        $favorite = $personal ? $this->favorite($user, $recent) : null;
        $around = [
            'because-you-read' => $recent === null ? null : $this->content->similarTo($user, $recent, ReasonType::BecauseYouRead, self::BACKFILL),
            'similar-to' => $favorite === null ? null : $this->content->similarTo($user, $favorite, ReasonType::SimilarThemes, self::BACKFILL),
        ];
        $ranked = $this->withNeighbors($user, $ranked, $around);

        if ($personal) {
            $specs['recommended'] = ['title' => __('Recommended For You'), 'featured' => false, 'items' => $this->recommended($user, $pooled, $lists, $ranked)];

            if ($recent !== null) {
                $specs['because-you-read'] = ['title' => __('Because You Read :title', ['title' => $recent->title]), 'featured' => false, 'items' => $this->fromCandidates('because-you-read', $around['because-you-read'], new Explanation(ReasonType::BecauseYouRead, $recent->title), $ranked), 'reason' => $recent->id, 'href' => $this->searchLike($user, $recent)];
            }
        }

        $specs['new-releases'] = ['title' => __('New Releases'), 'featured' => false, 'items' => $this->fromCandidates('new-releases', $lists['new_releases'] ?? new Collection, new Explanation(ReasonType::NewRelease), $ranked)];

        if ($favorite !== null) {
            $specs['similar-to'] = ['title' => __('Similar To :title', ['title' => $favorite->title]), 'featured' => false, 'items' => $this->fromCandidates('similar-to', $around['similar-to'], new Explanation(ReasonType::SimilarThemes, $favorite->title), $ranked), 'reason' => $favorite->id];
        }

        $specs['trending'] = ['title' => __('Trending Now'), 'featured' => true, 'items' => $this->fromCandidates('trending', $lists['trending'] ?? new Collection, new Explanation(ReasonType::Trending), $ranked)];
        $specs['popular-in-genres'] = ['title' => __('Popular in Your Genres'), 'featured' => false, 'items' => $this->fromCandidates('popular-in-genres', $lists['popular_in_genres'] ?? new Collection, null, $ranked, ReasonType::MatchesInterest)];
        $specs['discover-new'] = ['title' => __('Discover Something New'), 'featured' => false, 'items' => $this->fromCandidates('discover-new', $lists['exploration'] ?? new Collection, new Explanation(ReasonType::Exploration), $ranked)];

        // The reader's own order is the master plan's, whatever order the specs were made in.
        $specs = collect(self::CANDIDATE_SHELVES)->filter(fn (string $key): bool => isset($specs[$key]))->mapWithKeys(fn (string $key): array => [$key => $specs[$key]])->all();

        $media = $this->media($user, collect($specs)->flatMap(fn (array $spec): array => array_map(fn (ServedItem $item): int => $item->mediaId, $spec['items']))->unique()->values()->all());

        $specs = collect($specs)->map(fn (array $spec): array => ['items' => array_values(array_filter($spec['items'], fn (ServedItem $item): bool => $media->has($item->mediaId)))] + $spec)->filter(fn (array $spec): bool => $spec['items'] !== []);
        $kept = $this->withoutRepeats($specs->map(fn (array $spec): array => $spec['items'])->all());
        $specs = $specs->filter(fn (array $spec, string $key): bool => isset($kept[$key]))->map(fn (array $spec, string $key): array => ['items' => $kept[$key]] + $spec);

        $ids = $this->logger->log($user, $specs->flatMap(fn (array $spec): array => $spec['items']), ModelVersion::activeFor('hybrid-weights'));

        $shelves = $specs->map(function (array $spec, string $key) use ($media, $ids): Shelf {
            $items = array_map(function (ServedItem $item) use ($media, $ids): Media {
                return (clone $media[$item->mediaId])
                    ->setAttribute('recommendation_id', $ids["{$item->shelf}:{$item->mediaId}"] ?? null)
                    ->setAttribute('reason_label', $item->label)
                    ->setAttribute('reason_media_id', $item->reasonMediaId);
            }, $spec['items']);

            return new Shelf($key, $spec['title'], new EloquentCollection($items), $spec['featured'], href: $spec['href'] ?? null);
        })->values();

        return [$shelves, $specs->filter(fn (array $spec): bool => isset($spec['reason']))->map(fn (array $spec): int => $spec['reason'])->all()];
    }

    /**
     * The shelves' items in order, each title on the first shelf that has it: a later shelf skips it and takes its next candidates, up to the size of a
     * shelf. A shelf left with fewer than `min_shelf_size` is dropped; Trending is never dropped, and when the others took all of its titles it keeps its own.
     *
     * @param  array<string, list<ServedItem>>  $shelves  shelf key => its candidates, best first
     * @return array<string, list<ServedItem>>
     */
    private function withoutRepeats(array $shelves): array
    {
        $shown = [];
        $kept = [];
        $minimum = (int) config('bookflix.recommendations.min_shelf_size');

        foreach ($shelves as $key => $candidates) {
            $items = array_slice(array_values(array_filter($candidates, fn (ServedItem $item): bool => ! isset($shown[$item->mediaId]))), 0, self::PER_SHELF);

            if ($key === 'trending' && $items === []) {
                $items = array_slice($candidates, 0, self::PER_SHELF);
            }

            if (count($items) < $minimum && ! ($key === 'trending' && $items !== [])) {
                continue;
            }

            foreach ($items as $item) {
                $shown[$item->mediaId] = true;
            }

            $kept[$key] = $items;
        }

        return $kept;
    }

    /**
     * The ranking with the neighbors of the shelves around one title added: those the pool did not hold are ranked in one more call.
     *
     * @param  Collection<int, RankedItem>|null  $ranked
     * @param  array<string, Collection<int, Candidate>|null>  $around
     * @return Collection<int, RankedItem>|null
     */
    private function withNeighbors(User $user, ?Collection $ranked, array $around): ?Collection
    {
        if ($ranked === null) {
            return null;
        }

        $missing = collect($around)->filter()->flatten(1)->reject(fn (Candidate $candidate): bool => $ranked->has($candidate->mediaId))->values();

        if ($missing->isEmpty()) {
            return $ranked;
        }

        return $ranked->union($this->ranker->rank($user, $missing, RankingWeights::active())->keyBy('mediaId'));
    }

    /**
     * Recommended For You: the whole pool ranked, diversified to twenty, with one place in `1 / epsilon` (every tenth by default) given to a title from the exploration pool.
     *
     * @param  Collection<int, Candidate>  $pooled
     * @param  array<string, Collection<int, Candidate>>  $lists
     * @param  Collection<int, RankedItem>|null  $ranked
     * @return list<ServedItem>
     */
    private function recommended(User $user, Collection $pooled, array $lists, ?Collection $ranked): array
    {
        $ordered = $ranked->sortBy(fn (RankedItem $item): array => [-$item->score, $item->mediaId])->values();
        $picked = $this->diversifier->rerank($ordered, 0.7, self::PER_SHELF);

        $exploration = collect($lists['exploration'] ?? [])->map(fn (Candidate $candidate): ?RankedItem => $ranked->get($candidate->mediaId))->filter()->values();
        // One slot in `1 / epsilon` (every tenth with the default 0.10); an epsilon of 0 gives exploration no slot.
        $epsilon = (float) config('bookflix.recommendations.epsilon');
        $picked = $this->injector->inject($picked, $exploration, $epsilon > 0 ? (int) round(1 / $epsilon) : 0, $this->random);

        $byId = $pooled->keyBy('mediaId');

        return array_values($picked->map(function (RankedItem $item) use ($byId): ServedItem {
            $explanation = $item->explanation;

            return new ServedItem(
                'recommended', $item->mediaId, $item->score, $explanation->type,
                // The ranker leaves the title out of a label whose reason title is gone or gated: the id goes with it.
                $explanation->reasonTitle === null ? null : $byId->get($item->mediaId)?->reasonMediaId,
                $item->features, array_filter(['genre' => $explanation->genre]), $explanation->label(),
            );
        })->all());
    }

    /**
     * A source's candidates as shelf items. Without a fixed explanation the ranker's applies when it is of the shelf's reason (a genre label
     * keeps its visibility check), and no label is shown otherwise. Titles the ranker dropped are dropped here.
     *
     * @param  Collection<int, Candidate>  $candidates
     * @param  Collection<int, RankedItem>|null  $ranked
     * @return list<ServedItem>
     */
    private function fromCandidates(string $shelf, Collection $candidates, ?Explanation $fixed, ?Collection $ranked, ?ReasonType $reason = null): array
    {
        $items = [];

        foreach ($candidates as $candidate) {
            if (count($items) >= ($shelf === 'trending' ? self::SOURCE_LIMIT : self::BACKFILL)) {
                break;
            }

            $rank = $ranked?->get($candidate->mediaId);

            if ($ranked !== null && $rank === null) {
                continue;
            }

            $explanation = $fixed ?? ($rank !== null && $rank->explanation->type === $reason ? $rank->explanation : new Explanation($reason ?? $candidate->reason));
            $reasonMediaId = $fixed?->reasonTitle !== null ? $candidate->reasonMediaId : null;

            $items[] = new ServedItem(
                $shelf, $candidate->mediaId, $rank->score ?? $candidate->score, $explanation->type, $reasonMediaId,
                $rank->features ?? [], array_filter(['genre' => $explanation->genre]), $explanation->label(),
            );
        }

        return $items;
    }

    /**
     * The search that "See all" of Because You Read opens: the title's own genres as filters, without a mature genre for a reader who may not see those.
     * Null when it has none, so the shelf shows no link rather than one to the whole catalog.
     */
    private function searchLike(User $user, Media $seed): ?string
    {
        $slugs = $seed->genres()->when(! MatureAccess::allows($user), fn ($genres) => $genres->where('genres.is_mature', false))->orderBy('genres.slug')->limit(5)->pluck('genres.slug')->all();

        return $slugs === [] ? null : route('search', ['filters' => ['genres' => $slugs]]);
    }

    /**
     * The reader's latest favorite that is not the title of the Because You Read shelf and that they may see.
     */
    private function favorite(User $user, ?Media $except): ?Media
    {
        $entries = LibraryEntry::query()->where('user_id', $user->id)->whereNotNull('favorited_at')
            ->when($except !== null, fn ($query) => $query->where('media_id', '!=', $except->id))
            ->orderByDesc('favorited_at')->orderByDesc('id')->limit(5)->get(['media_id']);

        if ($entries->isEmpty()) {
            return null;
        }

        $visible = Media::query()->unmerged()->visibleTo($user)->whereIn('media.id', $entries->pluck('media_id')->all())->get()->keyBy('id');

        foreach ($entries as $entry) {
            if ($visible->has($entry->media_id)) {
                return $visible[$entry->media_id];
            }
        }

        return null;
    }

    /**
     * What a tile reads of these titles, the ones the reader may see, by id.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, Media>
     */
    private function media(User $user, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return Media::query()->unmerged()->visibleTo($user)->whereIn('media.id', $ids)
            ->with(['mediaType', 'publicPrimaryDewey.deweyClass'])->get()->keyBy('id');
    }

    /**
     * What the cache keeps of the shelves: no models, only ids and the text each tile shows, as `[media id, recommendation id, label, reason media id]`,
     * and with a shelf the id of the title its heading names. The ids of the titles a text names let the read drop that text when the reader may no
     * longer see the title.
     *
     * @param  Collection<int, Shelf>  $shelves
     * @param  array<string, int>  $reasons  shelf key => the media its title names
     * @return array<int, array<string, mixed>>
     */
    private function digest(Collection $shelves, array $reasons): array
    {
        $digest = [];

        foreach ($shelves as $shelf) {
            $items = [];

            foreach ($shelf->items as $media) {
                $items[] = [$media->getKey(), $media->getAttribute('recommendation_id'), $media->getAttribute('reason_label'), $media->getAttribute('reason_media_id')];
            }

            $digest[] = ['key' => $shelf->key, 'title' => $shelf->title, 'featured' => $shelf->featured, 'reason_media_id' => $reasons[$shelf->key] ?? null, 'href' => $shelf->href, 'items' => $items];
        }

        return $digest;
    }

    /**
     * The shelves of a digest, loaded fresh through the mature gate; a title that is gone or gated now is left out, and a shelf that empties with it.
     * A shelf whose heading names a title the reader may no longer see is left out, and a tile label naming one is dropped.
     *
     * @param  array<mixed>  $digest
     * @return Collection<int, Shelf>
     */
    private function hydrate(User $user, array $digest): Collection
    {
        $ids = [];
        $reasons = [];

        foreach ($digest as $shelf) {
            if (($shelf['reason_media_id'] ?? null) !== null) {
                $reasons[] = (int) $shelf['reason_media_id'];
            }

            foreach ($shelf['items'] as $item) {
                $ids[] = (int) $item[0];

                if (($item[3] ?? null) !== null) {
                    $reasons[] = (int) $item[3];
                }
            }
        }

        $media = $this->media($user, array_values(array_unique($ids)));
        $seen = $reasons === [] ? [] : array_flip(Media::query()->visibleTo($user)->whereIn('media.id', array_unique($reasons))->pluck('media.id')->all());
        $shelves = new Collection;

        foreach ($digest as $shelf) {
            if (($shelf['reason_media_id'] ?? null) !== null && ! isset($seen[$shelf['reason_media_id']])) {
                continue;
            }

            $items = [];

            foreach ($shelf['items'] as $item) {
                if ($media->has($item[0])) {
                    $reason = $item[3] ?? null;
                    $label = $reason !== null && ! isset($seen[$reason]) ? null : $item[2];
                    $items[] = (clone $media[$item[0]])->setAttribute('recommendation_id', $item[1])->setAttribute('reason_label', $label)->setAttribute('reason_media_id', $reason);
                }
            }

            if ($items !== []) {
                $shelves->push(new Shelf($shelf['key'], $shelf['title'], new EloquentCollection($items), $shelf['featured'], href: $shelf['href'] ?? null));
            }
        }

        return $shelves;
    }
}
