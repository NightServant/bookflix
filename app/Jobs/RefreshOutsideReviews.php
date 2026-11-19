<?php

namespace App\Jobs;

use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Models\ExternalRating;
use App\Models\ExternalReview;
use App\Models\ExternalSource;
use App\Models\Media;
use App\Reviews\OffersReviews;
use App\Reviews\OutsideReviewData;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Reads the outside reviews and ratings of one story from every enabled provider that offers them and knows the story (P10-R20).
 * Sources are independent: one that fails, is throttled or has no credentials keeps its stored rows and leaves the others alone.
 * Unique per story while queued, so opening a page twice queues it once.
 */
class RefreshOutsideReviews implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public function __construct(public int $mediaId)
    {
        $this->onQueue('ingestion');
    }

    public function uniqueId(): string
    {
        return (string) $this->mediaId;
    }

    public function handle(): void
    {
        $media = Media::find($this->mediaId);

        if ($media === null || $media->merged_into_id !== null) {
            return;
        }

        $ids = $media->externalIds()->with('externalSource')->get()
            ->filter(fn ($id) => $id->externalSource->enabled)
            ->keyBy(fn ($id) => $id->externalSource->key);

        foreach ($ids as $key => $id) {
            if (! $this->enabled($key)) {
                continue;
            }

            $provider = $this->provider($key);

            if ($provider === null || $this->throttled($key)) {
                continue;
            }

            try {
                $data = $provider->outsideReviews($id->external_id);
            } catch (RateLimitedByProvider) {
                continue;
            } catch (ProviderUnavailable $exception) {
                Log::warning("Outside reviews from {$key} skipped: {$exception->getMessage()}", ['media_id' => $media->id]);

                continue;
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            $this->store($media, $key, $data);
        }
    }

    private function enabled(string $key): bool
    {
        return ExternalSource::where('key', $key)->where('enabled', true)->exists();
    }

    private function provider(string $key): ?OffersReviews
    {
        $class = config("bookflix.providers.{$key}.class");
        $provider = is_string($class) ? app($class) : null;

        return $provider instanceof OffersReviews ? $provider : null;
    }

    /**
     * Takes one token from the provider's own limiter, the bucket its page jobs draw from, and says whether none was left.
     */
    private function throttled(string $key): bool
    {
        $name = "provider:{$key}";
        $limit = RateLimiter::limiter($name)?->__invoke($this);

        if (! $limit instanceof Limit) {
            return false;
        }

        $bucket = md5($name.$limit->key);

        if (RateLimiter::tooManyAttempts($bucket, $limit->maxAttempts)) {
            return true;
        }

        RateLimiter::hit($bucket, $limit->decaySeconds);

        return false;
    }

    private function store(Media $media, string $source, OutsideReviewData $data): void
    {
        $now = now();
        $seen = [];

        foreach (array_slice($data->reviews, 0, 10) as $review) {
            $seen[] = $review->externalId;

            // The hide columns are not in the update list, so a moderator's decision survives the refresh.
            ExternalReview::query()->upsert([[
                'media_id' => $media->id,
                'source' => $source,
                'external_id' => $review->externalId,
                'author_name' => $review->authorName,
                'rating' => $review->rating,
                'excerpt' => $review->excerpt,
                'url' => $review->url,
                'published_at' => $review->publishedAt,
                'fetched_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['source', 'external_id'], ['media_id', 'author_name', 'rating', 'excerpt', 'url', 'published_at', 'fetched_at', 'updated_at']);
        }

        // A hidden review that is no longer returned stays, so a moderator's decision is still there if the author posts it again.
        ExternalReview::where('media_id', $media->id)->where('source', $source)
            ->whereNotIn('external_id', $seen)->whereNull('hidden_at')->delete();

        if ($data->rating !== null) {
            ExternalRating::updateOrCreate(
                ['media_id' => $media->id, 'source' => $source],
                ['score' => $data->rating->score, 'votes' => $data->rating->votes, 'url' => $data->rating->url, 'fetched_at' => $now],
            );
        } else {
            ExternalRating::where('media_id', $media->id)->where('source', $source)->delete();
        }
    }
}
