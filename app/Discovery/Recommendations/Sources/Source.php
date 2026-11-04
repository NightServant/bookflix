<?php

namespace App\Discovery\Recommendations\Sources;

use App\Discovery\Recommendations\Candidate;
use App\Discovery\Recommendations\CandidateSource;
use App\Discovery\Recommendations\SeedItems;
use App\Enums\ReasonType;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What every source shares: the titles a reader may be offered. That is live media (not deleted, not merged away) the reader may see
 * (`Media::visibleTo`, so the mature gate holds for every source), that is not in their library in any status, and that they did not dismiss
 * from a recommendation. Similarity rows are read only from the active version of their method, so a candidate version is never served.
 */
abstract class Source implements CandidateSource
{
    /** The most seed titles a source reads neighbors of; the strongest are kept. */
    protected const SEED_LIMIT = 50;

    public function __construct(protected SeedItems $seeds) {}

    /**
     * @return Collection<int, Candidate>
     */
    public function candidatesFor(User $user, int $limit): Collection
    {
        return $limit < 1 ? new Collection : $this->gather($user, $limit)->take($limit)->values();
    }

    /**
     * @return Collection<int, Candidate>
     */
    abstract protected function gather(User $user, int $limit): Collection;

    /**
     * The media this reader may be offered, as a query to join or restrict by.
     *
     * @return Builder<Media>
     */
    protected function eligible(User $user): Builder
    {
        return Media::query()->unmerged()->visibleTo($user)
            ->whereNotExists(fn ($library) => $library->select(DB::raw(1))->from('user_library')
                ->whereColumn('user_library.media_id', 'media.id')->where('user_library.user_id', $user->id))
            ->whereNotExists(fn ($dismissed) => $dismissed->select(DB::raw(1))->from('recommendations')
                ->join('recommendation_events', 'recommendation_events.recommendation_id', '=', 'recommendations.id')
                ->whereColumn('recommendations.media_id', 'media.id')
                ->where('recommendations.user_id', $user->id)
                ->where('recommendation_events.event', 'dismiss'));
    }

    /**
     * The strongest seed titles, id => strength.
     *
     * @return Collection<int, float>
     */
    protected function seedsOf(User $user): Collection
    {
        return $this->seeds->for($user)->take(self::SEED_LIMIT);
    }

    /**
     * The neighbors of the seeds under one method, from its active version, that the reader may be offered.
     *
     * @param  Collection<int, float>  $seeds  media id => strength
     * @return Collection<int, stdClass>
     */
    protected function neighborsOf(User $user, Collection $seeds, string $method): Collection
    {
        if ($seeds->isEmpty()) {
            return new Collection;
        }

        return DB::table('media_similarities as s')
            ->join('model_versions as v', 'v.id', '=', 's.model_version_id')
            ->joinSub($this->eligible($user)->select('media.id'), 'eligible', 'eligible.id', '=', 's.similar_media_id')
            ->where('v.status', 'active')
            ->where('v.kind', $method)
            ->where('s.method', $method)
            ->whereIn('s.media_id', $seeds->keys()->all())
            ->orderByDesc('s.score')->orderBy('s.similar_media_id')
            ->get(['s.media_id', 's.similar_media_id', 's.score']);
    }

    /**
     * Candidates for a ranked list of ids, best first, scored by position: 1 for the first down to 1/n for the last.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Candidate>
     */
    protected function byPosition(array $ids, ReasonType $reason): Collection
    {
        $count = count($ids);

        return collect($ids)->values()->map(fn (int $id, int $position): Candidate => new Candidate($id, $this->key(), ($count - $position) / $count, $reason));
    }
}
