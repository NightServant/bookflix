<?php

namespace App\Discovery\Recommendations\Sources;

use App\Discovery\Recommendations\Candidate;
use App\Discovery\Recommendations\GenreAffinity;
use App\Discovery\Recommendations\SeedItems;
use App\Enums\ReasonType;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Random\Randomizer;

/**
 * A few titles outside the reader's comfort zone: a random pick of well-rated titles (average 3.75 of 5 or better from at least 5 ratings)
 * that carry none of their three top genres. When too few are well rated it fills from any rated title, then from any title. The
 * randomizer is injected so a test can fix the pick.
 */
class ExplorationSource extends Source
{
    private const MIN_AVERAGE = 3.75;

    private const MIN_RATINGS = 5;

    /** The most titles a tier offers to the draw: the most rated first, so the pool stays small on a big catalog. */
    private const POOL = 500;

    public function __construct(SeedItems $seeds, private GenreAffinity $affinity, private Randomizer $random)
    {
        parent::__construct($seeds);
    }

    public function key(): string
    {
        return 'exploration';
    }

    protected function gather(User $user, int $limit): Collection
    {
        $genres = array_keys($this->affinity->top($user));
        $picked = [];

        $tiers = [$this->wellRated(...), $this->rated(...), $this->any(...)];

        foreach ($tiers as $tier) {
            if (count($picked) >= $limit) {
                break;
            }

            $pool = $tier($this->eligible($user))
                ->when($genres !== [], fn (Builder $media) => $media->whereNotExists(fn ($has) => $has->selectRaw('1')->from('media_genres')
                    ->whereColumn('media_genres.media_id', 'media.id')->whereIn('media_genres.genre_id', $genres)))
                ->whereNotIn('media.id', array_keys($picked) ?: [0])
                ->orderByDesc('media.ratings_count')->orderBy('media.id')
                ->limit(self::POOL)
                ->get(['media.id', 'media.average_rating']);

            $byId = $pool->keyBy('id');

            foreach (array_map(intval(...), $this->random->shuffleArray($pool->modelKeys())) as $id) {
                if (count($picked) >= $limit) {
                    break;
                }

                $media = $byId[$id];
                $picked[$media->id] = $media;
            }
        }

        return collect($picked)->map(fn ($media): Candidate => new Candidate(
            $media->id,
            $this->key(),
            $media->average_rating === null ? 0.0 : min(1.0, (float) $media->average_rating / 5),
            ReasonType::Exploration,
        ))->values();
    }

    /**
     * @param  Builder<Media>  $media
     * @return Builder<Media>
     */
    private function wellRated(Builder $media): Builder
    {
        return $media->where('media.average_rating', '>=', self::MIN_AVERAGE)->where('media.ratings_count', '>=', self::MIN_RATINGS);
    }

    /**
     * @param  Builder<Media>  $media
     * @return Builder<Media>
     */
    private function rated(Builder $media): Builder
    {
        return $media->where('media.ratings_count', '>', 0);
    }

    /**
     * @param  Builder<Media>  $media
     * @return Builder<Media>
     */
    private function any(Builder $media): Builder
    {
        return $media;
    }
}
