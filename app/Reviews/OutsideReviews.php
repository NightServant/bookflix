<?php

namespace App\Reviews;

use App\Jobs\RefreshOutsideReviews;
use App\Models\ExternalRating;
use App\Models\ExternalReview;
use App\Models\Media;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Outside reviews and ratings of a story (P10-R20): when to refresh them, and what the story page shows.
 */
final class OutsideReviews
{
    /**
     * Queue a refresh when the story's outside data is missing or older than `bookflix.reviews.outside_stale_days`, at most once a day per
     * story. Returns whether one was queued. Only sources that are enabled are asked, so with every source off the job does nothing.
     */
    public static function refreshIfStale(Media $media): bool
    {
        $newest = collect([
            ExternalReview::where('media_id', $media->id)->max('fetched_at'),
            ExternalRating::where('media_id', $media->id)->max('fetched_at'),
        ])->filter()->map(fn ($at) => Carbon::parse($at))->max();

        if ($newest !== null && $newest->greaterThan(now()->subDays((int) config('bookflix.reviews.outside_stale_days', 30)))) {
            return false;
        }

        if (! Cache::lock("outside-reviews:{$media->id}", 86400)->get()) {
            return false;
        }

        RefreshOutsideReviews::dispatch($media->id);

        return true;
    }

    /**
     * The reviews a reader may see, newest first.
     *
     * @return Collection<int, ExternalReview>
     */
    public static function visibleReviews(Media $media, int $limit = 30): Collection
    {
        return ExternalReview::where('media_id', $media->id)->visible()
            ->orderByRaw('published_at desc nulls last')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * The ratings by source, in source order.
     *
     * @return Collection<string, ExternalRating>
     */
    public static function ratings(Media $media): Collection
    {
        return ExternalRating::where('media_id', $media->id)->orderBy('source')->get()->keyBy('source');
    }
}
