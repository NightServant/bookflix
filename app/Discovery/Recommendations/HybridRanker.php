<?php

namespace App\Discovery\Recommendations;

use App\Classification\Dewey\DeweyHierarchy;
use App\Models\DeweyClass;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use App\Models\User;
use App\Models\UserPreference;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Scores candidates for one reader with a weighted sum of eight features, each in [0, 1] (a missing one is 0), so the score is in [0, 1] too:
 * when the weights add up to more than one the sum is divided by that total. Everything is loaded in batches, so the number of queries does not
 * depend on how many candidates there are.
 *
 * Media that is gone, merged or gated for the reader is dropped here as well, so a cached or stale candidate list cannot serve it.
 * Popularity is `media.popularity_score` plus the small share of Bookflix's own activity that Trending uses (`Media::TRENDING_BLEND`), capped
 * at 1; it is not `media_popularity.popularity_percentile`, which counts only Bookflix's few interactions (revision item 4).
 */
class HybridRanker
{
    /** Freshness halves roughly every 125 days: exp(-age / 180). */
    private const FRESHNESS_DAYS = 180.0;

    /** The most seed titles the neighbors are read for; the strongest are kept (as in the sources). */
    private const SEED_LIMIT = 50;

    public function __construct(private SeedItems $seeds, private DeweyHierarchy $dewey) {}

    /**
     * @param  Collection<int, Candidate>  $candidates
     * @return Collection<int, RankedItem> best first, ties by media id
     */
    public function rank(User $user, Collection $candidates, RankingWeights $weights): Collection
    {
        $best = [];

        foreach ($candidates as $candidate) {
            if (! isset($best[$candidate->mediaId]) || $candidate->score > $best[$candidate->mediaId]->score) {
                $best[$candidate->mediaId] = $candidate;
            }
        }

        if ($best === []) {
            return new Collection;
        }

        $media = $this->mediaFor($user, array_keys($best));
        $best = array_intersect_key($best, $media->all());

        if ($best === []) {
            return new Collection;
        }

        $seeds = $this->seeds->for($user)->take(self::SEED_LIMIT);
        $ids = array_keys($best);
        [$content, $collaborative] = $this->neighbors($seeds, $ids);
        $dewey = $this->deweySimilarity($seeds, $ids);
        $preference = $this->preference($user, $seeds, $ids);
        $reasons = $this->reasonMedia($best);
        $total = max(1.0, array_sum($weights->toArray()));

        return collect($best)
            ->map(function (Candidate $candidate, int $id) use ($media, $content, $collaborative, $dewey, $preference, $reasons, $weights, $total): RankedItem {
                $row = $media[$id];
                $popularity = $this->clamp((float) $row->getAttribute('blend'));
                $features = [
                    'content_similarity' => $this->clamp($content[$id] ?? 0.0),
                    'collaborative_score' => $this->clamp($collaborative[$id] ?? 0.0),
                    'dewey_similarity' => $this->clamp($dewey[$id] ?? 0.0),
                    'popularity' => $popularity,
                    'freshness' => $this->freshness($row->published_on),
                    'personal_preference' => $this->clamp($preference[$id] ?? 0.0),
                    'context' => 0.0,
                    'novelty' => $this->clamp(1.0 - $popularity),
                ];

                $score = $weights->content * $features['content_similarity']
                    + $weights->collaborative * $features['collaborative_score']
                    + $weights->dewey * $features['dewey_similarity']
                    + $weights->popularity * $features['popularity']
                    + $weights->freshness * $features['freshness']
                    + $weights->preference * $features['personal_preference']
                    + $weights->context * $features['context']
                    + $weights->novelty * $features['novelty'];

                return new RankedItem($id, $this->clamp($score / $total), $features, Explanation::fromCandidate($candidate, $reasons[$candidate->reasonMediaId] ?? null));
            })
            ->sortBy(fn (RankedItem $item): array => [-$item->score, $item->mediaId])
            ->values();
    }

    /**
     * The candidates' media the reader may see, with the Trending blend as `blend`, keyed by id.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Media>
     */
    private function mediaFor(User $user, array $ids): Collection
    {
        return Media::query()->unmerged()->visibleTo($user)
            ->leftJoin('media_popularity', 'media_popularity.media_id', '=', 'media.id')
            ->whereIn('media.id', $ids)
            ->select('media.id', 'media.published_on')
            ->selectRaw(Media::TRENDING_BLEND.' as blend', [Media::trendingWeight()])
            ->get()
            ->keyBy('id');
    }

    /**
     * Content similarity (best `neighbor score × seed strength` over the text methods) and collaborative score (the strength weighted mean of the
     * item_cf neighbor scores of the seeds that lead to the title), from the active versions only.
     *
     * @param  Collection<int, float>  $seeds
     * @param  list<int>  $ids
     * @return array{0: array<int, float>, 1: array<int, float>}
     */
    private function neighbors(Collection $seeds, array $ids): array
    {
        if ($seeds->isEmpty()) {
            return [[], []];
        }

        $rows = DB::table('media_similarities as s')
            ->join('model_versions as v', 'v.id', '=', 's.model_version_id')
            ->where('v.status', 'active')
            ->whereColumn('v.kind', 's.method')
            ->whereIn('s.method', ['lsa', 'embedding', 'item_cf'])
            ->whereIn('s.media_id', $seeds->keys()->all())
            ->whereIn('s.similar_media_id', $ids)
            ->get(['s.media_id', 's.similar_media_id', 's.method', 's.score']);

        $content = [];
        $weighted = [];
        $strengths = [];

        foreach ($rows as $row) {
            $strength = $seeds[$row->media_id];

            if ($row->method === 'item_cf') {
                $weighted[$row->similar_media_id] = ($weighted[$row->similar_media_id] ?? 0.0) + $row->score * $strength;
                $strengths[$row->similar_media_id] = ($strengths[$row->similar_media_id] ?? 0.0) + $strength;
            } else {
                $content[$row->similar_media_id] = max($content[$row->similar_media_id] ?? 0.0, $row->score * $strength);
            }
        }

        $collaborative = [];

        foreach ($weighted as $id => $sum) {
            $collaborative[$id] = $strengths[$id] > 0 ? $sum / $strengths[$id] : 0.0;
        }

        return [$content, $collaborative];
    }

    /**
     * The closest public primary class of any seed to each candidate's (0 when either has none).
     *
     * @param  Collection<int, float>  $seeds
     * @param  list<int>  $ids
     * @return array<int, float>
     */
    private function deweySimilarity(Collection $seeds, array $ids): array
    {
        if ($seeds->isEmpty()) {
            return [];
        }

        $classes = MediaDeweyClass::query()->publicPrimary()
            ->join('dewey_classes', 'dewey_classes.id', '=', 'media_dewey_classes.dewey_class_id')
            ->whereIn('media_dewey_classes.media_id', [...$seeds->keys()->all(), ...$ids])
            ->get(['media_dewey_classes.media_id', 'dewey_classes.path', 'dewey_classes.level'])
            ->groupBy('media_id')
            ->map(fn (Collection $rows): Collection => $rows->map(fn (MediaDeweyClass $row): DeweyClass => (new DeweyClass)->forceFill(['path' => $row->getAttribute('path'), 'level' => $row->getAttribute('level')])));

        $seedClasses = $seeds->keys()->flatMap(fn (int $id): Collection => $classes[$id] ?? new Collection);
        $similarity = [];

        if ($seedClasses->isEmpty()) {
            return [];
        }

        foreach ($ids as $id) {
            foreach ($classes[$id] ?? [] as $class) {
                foreach ($seedClasses as $seedClass) {
                    $similarity[$id] = max($similarity[$id] ?? 0.0, $this->dewey->similarity($seedClass, $class));
                }
            }
        }

        return $similarity;
    }

    /**
     * Each candidate's affinity: the weights of the genres, themes, types and creators the reader chose that it carries, plus the strength of
     * the seeds sharing its genres; divided by the best candidate's, so the best is 1 and a title with none is 0.
     *
     * @param  Collection<int, float>  $seeds
     * @param  list<int>  $ids
     * @return array<int, float>
     */
    private function preference(User $user, Collection $seeds, array $ids): array
    {
        $affinity = [];

        foreach (UserPreference::query()->where('user_id', $user->id)->whereIn('preferable_type', ['genre', 'theme', 'media_type', 'creator'])->get(['preferable_type', 'preferable_id', 'weight']) as $preference) {
            $key = $preference->preferable_type.':'.$preference->preferable_id;
            $affinity[$key] = ($affinity[$key] ?? 0.0) + $preference->weight;
        }

        $everything = [...$seeds->keys()->all(), ...$ids];

        $attributes = DB::table('media_genres')->whereIn('media_id', $everything)->select('media_id', DB::raw("'genre' as kind"), 'genre_id as ref')
            ->unionAll(DB::table('media_themes')->whereIn('media_id', $everything)->select('media_id', DB::raw("'theme' as kind"), 'theme_id as ref'))
            ->unionAll(DB::table('media_creators')->whereIn('media_id', $everything)->select('media_id', DB::raw("'creator' as kind"), 'creator_id as ref'))
            ->unionAll(DB::table('media_additional_types')->whereIn('media_id', $everything)->select('media_id', DB::raw("'media_type' as kind"), 'media_type_id as ref'))
            ->unionAll(DB::table('media')->whereIn('id', $everything)->select('id as media_id', DB::raw("'media_type' as kind"), 'media_type_id as ref'))
            ->get()
            ->groupBy('media_id');

        foreach ($seeds as $seedId => $strength) {
            foreach ($attributes[$seedId] ?? [] as $attribute) {
                if ($attribute->kind === 'genre') {
                    $key = 'genre:'.$attribute->ref;
                    $affinity[$key] = ($affinity[$key] ?? 0.0) + $strength;
                }
            }
        }

        $raw = [];

        foreach ($ids as $id) {
            $raw[$id] = 0.0;

            foreach ($attributes[$id] ?? [] as $attribute) {
                $raw[$id] += $affinity[$attribute->kind.':'.$attribute->ref] ?? 0.0;
            }
        }

        $max = max($raw);

        return $max > 0 ? array_map(fn (float $value): float => $value / $max, $raw) : [];
    }

    /**
     * The titles the winning candidates name in their sentence, keyed by id.
     *
     * @param  array<int, Candidate>  $candidates
     * @return Collection<int, Media>
     */
    private function reasonMedia(array $candidates): Collection
    {
        $ids = collect($candidates)->pluck('reasonMediaId')->filter()->unique()->values()->all();

        return $ids === [] ? new Collection : Media::query()->whereIn('id', $ids)->get(['id', 'title'])->keyBy('id');
    }

    private function freshness(?DateTimeInterface $publishedOn): float
    {
        if ($publishedOn === null) {
            return 0.0;
        }

        $days = max(0.0, (now()->getTimestamp() - $publishedOn->getTimestamp()) / 86400);

        return $this->clamp(exp(-$days / self::FRESHNESS_DAYS));
    }

    private function clamp(float $value): float
    {
        return is_finite($value) ? min(1.0, max(0.0, $value)) : 0.0;
    }
}
