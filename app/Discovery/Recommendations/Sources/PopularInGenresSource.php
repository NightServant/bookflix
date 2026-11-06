<?php

namespace App\Discovery\Recommendations\Sources;

use App\Discovery\Recommendations\Candidate;
use App\Discovery\Recommendations\GenreAffinity;
use App\Discovery\Recommendations\SeedItems;
use App\Enums\ReasonType;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The most popular titles (popularity percentile) in the three genres the reader leans to most, each carrying the genre that matched.
 */
class PopularInGenresSource extends Source
{
    public function __construct(SeedItems $seeds, private GenreAffinity $affinity)
    {
        parent::__construct($seeds);
    }

    public function key(): string
    {
        return 'popular_in_genres';
    }

    protected function gather(User $user, int $limit): Collection
    {
        $genres = array_keys($this->affinity->top($user));

        if ($genres === []) {
            return new Collection;
        }

        $names = Genre::query()->whereIn('id', $genres)->pluck('name', 'id');

        $rows = $this->eligible($user)
            ->join('media_genres', 'media_genres.media_id', '=', 'media.id')
            ->leftJoin('media_popularity', 'media_popularity.media_id', '=', 'media.id')
            ->whereIn('media_genres.genre_id', $genres)
            ->select('media.id', 'media_genres.genre_id')
            ->selectRaw('coalesce(media_popularity.popularity_percentile, 0) as percentile')
            ->orderByDesc('percentile')->orderByDesc('media.ratings_count')->orderBy('media.id')
            ->limit($limit * count($genres))
            ->get();

        // A title in several of the genres is proposed once, under the one the reader leans to most.
        $rank = array_flip($genres);

        return $rows->sortBy(fn ($row): int => $rank[$row->getAttribute('genre_id')])->unique('id')
            ->sortBy(fn ($row): array => [-$row->getAttribute('percentile'), $row->id])
            ->map(fn ($row): Candidate => new Candidate($row->id, $this->key(), (float) $row->getAttribute('percentile'), ReasonType::MatchesInterest, null, ['genre' => $names[$row->getAttribute('genre_id')] ?? null]))
            ->values();
    }
}
