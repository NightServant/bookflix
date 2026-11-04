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
 * Each title on a built shelf carries two extra attributes for the tile: `recommendation_id` (null when nothing was logged) and `reason_label`.
 */
class ShelfBuilder
{
    /** The most titles on a shelf. */
    private const PER_SHELF = 20;

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

        $shelves = $this->build($user);

        Cache::put($key, $this->digest($shelves), now()->addMinutes((int) config('bookflix.recommendations.cache_minutes')));

        return $shelves;
    }

    /**
     * @return Collection<int, Shelf>
     */
    private function build(User $user): Collection
    {
        $enabled = $user->personalizationEnabled();
        $personal = $enabled && $this->seeds->for($user)->count() >= (int) config('bookflix.recommendations.cold_start_min_interactions');

        $lists = $this->pool->bySource($user, self::SOURCE_LIMIT);
        $pooled = $this->pool->combine($user, $lists);

        // Ranking gives every title its features and drops what the reader may not see; an opted-out reader's history is not read at all.
        $ranked = $enabled ? $this->ranker->rank($user, $pooled, RankingWeights::active())->keyBy('mediaId') : null;

        /** @var array<string, array{title: string, featured: bool, items: list<ServedItem>}> $specs */
        $specs = [];

        if ($personal) {
            $specs['recommended'] = ['title' => __('Recommended For You'), 'featured' => false, 'items' => $this->recommended($user, $pooled, $lists, $ranked)];

            $recent = $this->seeds->mostRecent($user);

            if ($recent !== null && ! $recent->isGatedFor($user)) {
                $specs['because-you-read'] = ['title' => __('Because You Read :title', ['title' => $recent->title]), 'featured' => false, 'items' => $this->around('because-you-read', $user, $recent, ReasonType::BecauseYouRead, $ranked)];
            }
        }

        $specs['new-releases'] = ['title' => __('New Releases'), 'featured' => false, 'items' => $this->fromCandidates('new-releases', $lists['new_releases'] ?? new Collection, new Explanation(ReasonType::NewRelease), $ranked)];

        if ($personal) {
            $favorite = $this->favorite($user, $recent ?? null);

            if ($favorite !== null) {
                $specs['similar-to'] = ['title' => __('Similar To :title', ['title' => $favorite->title]), 'featured' => false, 'items' => $this->around('similar-to', $user, $favorite, ReasonType::SimilarThemes, $ranked)];
            }
        }

        $specs['trending'] = ['title' => __('Trending Now'), 'featured' => true, 'items' => $this->fromCandidates('trending', $lists['trending'] ?? new Collection, new Explanation(ReasonType::Trending), $ranked)];
        $specs['popular-in-genres'] = ['title' => __('Popular in Your Genres'), 'featured' => false, 'items' => $this->fromCandidates('popular-in-genres', $lists['popular_in_genres'] ?? new Collection, null, $ranked, ReasonType::MatchesInterest)];
        $specs['discover-new'] = ['title' => __('Discover Something New'), 'featured' => false, 'items' => $this->fromCandidates('discover-new', $lists['exploration'] ?? new Collection, new Explanation(ReasonType::Exploration), $ranked)];

        // The reader's own order is the master plan's, whatever order the specs were made in.
        $specs = collect(self::CANDIDATE_SHELVES)->filter(fn (string $key): bool => isset($specs[$key]))->mapWithKeys(fn (string $key): array => [$key => $specs[$key]])->all();

        $media = $this->media($user, collect($specs)->flatMap(fn (array $spec): array => array_map(fn (ServedItem $item): int => $item->mediaId, $spec['items']))->unique()->values()->all());

        $specs = collect($specs)->map(fn (array $spec): array => ['items' => array_values(array_filter($spec['items'], fn (ServedItem $item): bool => $media->has($item->mediaId)))] + $spec)->filter(fn (array $spec): bool => $spec['items'] !== []);

        $ids = $this->logger->log($user, $specs->flatMap(fn (array $spec): array => $spec['items']), ModelVersion::activeFor('hybrid-weights'));

        return $specs->map(function (array $spec, string $key) use ($media, $ids): Shelf {
            $items = array_map(function (ServedItem $item) use ($media, $ids): Media {
                return (clone $media[$item->mediaId])
                    ->setAttribute('recommendation_id', $ids["{$item->shelf}:{$item->mediaId}"] ?? null)
                    ->setAttribute('reason_label', $item->label);
            }, $spec['items']);

            return new Shelf($key, $spec['title'], new EloquentCollection($items), $spec['featured']);
        })->values();
    }

    /**
     * Recommended For You: the whole pool ranked, diversified to twenty, with every tenth place given to a title from the exploration pool.
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
        $picked = $this->injector->inject($picked, $exploration, 10, $this->random);

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
     * The neighbors of one title the reader read or loved, labelled with it.
     *
     * @param  Collection<int, RankedItem>|null  $ranked
     * @return list<ServedItem>
     */
    private function around(string $shelf, User $user, Media $seed, ReasonType $reason, ?Collection $ranked): array
    {
        return $this->fromCandidates($shelf, $this->content->similarTo($user, $seed, $reason, self::PER_SHELF), new Explanation($reason, $seed->title), $ranked);
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
            if (count($items) >= self::PER_SHELF) {
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
     * What the cache keeps of the shelves: no models, only ids and the text each tile shows, as `[media id, recommendation id, label]`.
     *
     * @param  Collection<int, Shelf>  $shelves
     * @return array<int, array<string, mixed>>
     */
    private function digest(Collection $shelves): array
    {
        $digest = [];

        foreach ($shelves as $shelf) {
            $items = [];

            foreach ($shelf->items as $media) {
                $items[] = [$media->getKey(), $media->getAttribute('recommendation_id'), $media->getAttribute('reason_label')];
            }

            $digest[] = ['key' => $shelf->key, 'title' => $shelf->title, 'featured' => $shelf->featured, 'items' => $items];
        }

        return $digest;
    }

    /**
     * The shelves of a digest, loaded fresh through the mature gate; a title that is gone or gated now is left out, and a shelf that empties with it.
     *
     * @param  array<mixed>  $digest
     * @return Collection<int, Shelf>
     */
    private function hydrate(User $user, array $digest): Collection
    {
        $ids = [];

        foreach ($digest as $shelf) {
            foreach ($shelf['items'] as $item) {
                $ids[] = (int) $item[0];
            }
        }

        $media = $this->media($user, array_values(array_unique($ids)));
        $shelves = new Collection;

        foreach ($digest as $shelf) {
            $items = [];

            foreach ($shelf['items'] as $item) {
                if ($media->has($item[0])) {
                    $items[] = (clone $media[$item[0]])->setAttribute('recommendation_id', $item[1])->setAttribute('reason_label', $item[2]);
                }
            }

            if ($items !== []) {
                $shelves->push(new Shelf($shelf['key'], $shelf['title'], new EloquentCollection($items), $shelf['featured']));
            }
        }

        return $shelves;
    }
}
