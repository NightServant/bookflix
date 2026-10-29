<?php

namespace App\Catalog;

use App\Models\ExternalSource;
use App\Models\Media;
use Illuminate\Support\Facades\DB;

/**
 * Where a story's rating comes from. `media.average_rating` (0 to 5) and `media.ratings_count` are not stored data of their own: they are the
 * blend of every source in `media_metrics`, the providers' and the readers' (the synthetic `bookflix` source, which App\Jobs\RecalculateMediaRating
 * keeps up to date). Both writers, the ingestion pipeline and that job, end by calling `blend`.
 */
class MediaRatings
{
    /** The two metrics a source contributes to the blend. */
    public const METRICS = ['ratings_count', 'average_rating'];

    /**
     * The synthetic source that holds the readers' aggregate. Created on first use, disabled, like the `isbn` source: it is never synced, listed
     * for an admin (see ExternalSource::providers) or weighed in popularity (see PopularityScorer). A second writer racing to create it is fine.
     */
    public static function readersSource(): ExternalSource
    {
        return ExternalSource::query()->where('key', ExternalSource::READERS)->first()
            ?? ExternalSource::createOrFirst(['key' => ExternalSource::READERS], ['name' => 'Bookflix readers', 'base_url' => 'urn:bookflix', 'attribution' => '', 'enabled' => false]);
    }

    /**
     * Refresh the media's rating columns from every source's metrics and save it: the counts add up, and the average is weighted by each
     * source's count (a source without a count weighs 1). A column an admin locked (`locked_fields`) is left alone.
     *
     * A column with nothing behind it (no count, or no average, in any source) keeps its value, unless `$clearEmpty` asks the columns to follow
     * the sources: then it goes back to 0 or null. The readers' job asks for it, because it is the one that can take the last rating away.
     */
    public static function blend(Media $media, bool $clearEmpty = false): void
    {
        $perSource = DB::table('media_metrics')->where('media_id', $media->id)->whereIn('metric', self::METRICS)
            ->get()->groupBy('external_source_id')
            ->map(fn ($rows): array => $rows->pluck('value', 'metric')->all());

        $count = (int) round($perSource->sum(fn (array $m): float => (float) ($m['ratings_count'] ?? 0)));
        $rated = $perSource->filter(fn (array $m): bool => isset($m['average_rating']));
        $weight = $rated->sum(fn (array $m): float => max((float) ($m['ratings_count'] ?? 0), 1));

        if (($count > 0 || $clearEmpty) && ! in_array('ratings_count', $media->locked_fields, true)) {
            $media->ratings_count = $count;
        }

        if (($weight > 0 || $clearEmpty) && ! in_array('average_rating', $media->locked_fields, true)) {
            $media->average_rating = $weight > 0
                ? (string) round($rated->sum(fn (array $m): float => (float) $m['average_rating'] * max((float) ($m['ratings_count'] ?? 0), 1)) / $weight, 2)
                : null;
        }

        $media->save();
    }
}
