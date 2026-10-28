<?php

namespace App\Discovery;

use App\Enums\AgeRating;
use App\Models\Media;
use Illuminate\Support\Facades\Cache;

/**
 * What the public landing page shows of the catalog, from one cached pool: the most popular stories that have a cover (adult titles
 * stay off a page anyone can open). One query when cold and none when warm; everything else is picked from the cached arrays, so a
 * decorative book never costs a query per visit. The hero's books, the cover pictures on the cards and the Top 10 all draw from it.
 *
 * @phpstan-type Cover array{id: int, title: string, cover: string, scene: string, popularity: float}
 */
class LandingShowcase
{
    /** How many of the most popular covered stories the page draws from. */
    public const POOL = 40;

    /** The Top 10 is the first ten of the pool, in popularity order. */
    public const TOP = 10;

    private const CACHE_MINUTES = 60;

    /**
     * The pool in popularity order. `scene` is the same-origin proxy path of the cover (relative, so the cache does not outlive a host change).
     *
     * @return list<Cover>
     */
    public function pool(): array
    {
        return Cache::remember('landing:pool:v2', now()->addMinutes(self::CACHE_MINUTES), fn (): array => array_values(Media::query()
            ->trending()
            ->whereNotNull('media.cover_url')
            ->whereNot('media.age_rating', AgeRating::Adult->value)
            ->limit(self::POOL)
            ->get(['media.id', 'media.title', 'media.cover_url', 'media.popularity_score'])
            ->map(fn (Media $media): array => [
                'id' => $media->id,
                'title' => $media->title,
                'cover' => (string) $media->cover_url,
                'popularity' => round((float) $media->popularity_score, 3),
                'scene' => route('covers.show', ['media' => $media->id, 'v' => substr(sha1((string) $media->cover_url), 0, 8)], false),
            ])
            ->all()));
    }

    /**
     * The ten most popular stories with a cover, rank one first.
     *
     * @return list<Cover>
     */
    public function topTen(): array
    {
        return array_slice($this->pool(), 0, self::TOP);
    }

    /**
     * A random pick of the pool, without repeats until the pool runs out, so the hero and the cards (cut from one pick) do not show the
     * same books while the pool is big enough.
     *
     * @return list<Cover>
     */
    public function random(int $count): array
    {
        $shuffled = $this->pool();
        shuffle($shuffled);

        if ($shuffled === []) {
            return [];
        }

        // A small catalog repeats from the start rather than leaving a hole.
        return array_map(fn (int $index): array => $shuffled[$index % count($shuffled)], range(0, $count - 1));
    }
}
