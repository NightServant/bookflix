<?php

namespace App\Discovery\Recommendations\Sources;

use App\Classification\Dewey\DeweyHierarchy;
use App\Discovery\Recommendations\Candidate;
use App\Discovery\Recommendations\SeedItems;
use App\Enums\ReasonType;
use App\Models\MediaDeweyClass;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Titles shelved near the seeds: those whose public primary class shares the seed's division (similarity of at least 2/3: 811 beside 813,
 * not 510 beside 813). The score is half the closeness times the seed's strength and half the title's popularity percentile.
 */
class DeweySimilarSource extends Source
{
    /** A division is the first two levels of a class path (800/810), so classes in it are at least this close. */
    private const MIN_SIMILARITY = 2 / 3;

    /** How many of the most popular titles of the divisions are scored. */
    private const POOL = 200;

    public function __construct(SeedItems $seeds, private DeweyHierarchy $dewey)
    {
        parent::__construct($seeds);
    }

    public function key(): string
    {
        return 'dewey_similar';
    }

    protected function gather(User $user, int $limit): Collection
    {
        $seeds = $this->seedsOf($user);

        if ($seeds->isEmpty()) {
            return new Collection;
        }

        $seedClasses = MediaDeweyClass::query()->publicPrimary()->whereIn('media_id', $seeds->keys()->all())->with('deweyClass')->get()
            ->filter(fn (MediaDeweyClass $row): bool => $row->deweyClass->level >= 2);

        $divisions = $seedClasses->map(fn (MediaDeweyClass $row): string => implode('/', array_slice(explode('/', $row->deweyClass->path), 0, 2)))->unique()->values();

        if ($divisions->isEmpty()) {
            return new Collection;
        }

        $rows = MediaDeweyClass::query()->publicPrimary()
            ->joinSub($this->eligible($user)->select('media.id'), 'eligible', 'eligible.id', '=', 'media_dewey_classes.media_id')
            ->join('dewey_classes', 'dewey_classes.id', '=', 'media_dewey_classes.dewey_class_id')
            ->leftJoin('media_popularity', 'media_popularity.media_id', '=', 'media_dewey_classes.media_id')
            ->where(fn ($within) => $divisions->each(fn (string $division) => $within->orWhere('dewey_classes.path', $division)->orWhere('dewey_classes.path', 'like', $division.'/%')))
            ->orderByRaw('coalesce(media_popularity.popularity_percentile, 0) desc')
            ->orderBy('media_dewey_classes.media_id')
            ->limit(self::POOL)
            ->get(['media_dewey_classes.*', 'media_popularity.popularity_percentile'])
            ->load('deweyClass');

        $best = [];

        foreach ($rows as $row) {
            foreach ($seedClasses as $seed) {
                $closeness = $this->dewey->similarity($seed->deweyClass, $row->deweyClass);

                if ($closeness < self::MIN_SIMILARITY - 1e-9) {
                    continue;
                }

                $score = $closeness * $seeds[$seed->media_id] * 0.5 + 0.5 * (float) $row->getAttribute('popularity_percentile');

                if ($score > ($best[$row->media_id][0] ?? -1)) {
                    $best[$row->media_id] = [$score, $seed->media_id];
                }
            }
        }

        return collect($best)
            ->map(fn (array $hit, int $id): Candidate => new Candidate($id, $this->key(), min(1.0, $hit[0]), ReasonType::SimilarThemes, $hit[1]))
            ->sortBy(fn (Candidate $candidate): array => [-$candidate->score, $candidate->mediaId])
            ->values();
    }
}
