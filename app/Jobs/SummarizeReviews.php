<?php

namespace App\Jobs;

use App\Enums\ReviewState;
use App\Ml\MlClient;
use App\Ml\MlServiceUnavailable;
use App\Models\Media;
use App\Models\Review;
use App\Models\ReviewSummary;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rebuild one story's "What readers say" summary (P10-R11) from its published, non-spoiler reviews by active, non-admin authors, newest first, joined
 * by blank lines and cut at 20,000 characters on a whole review. Fewer than `review_summary_min_reviews` such reviews: the summary is deleted and the
 * ML service is not asked. The same text as the stored summary's: nothing is asked. No summary found: deleted. ML service down: the stored summary
 * stays, and the job ends without an error. Unique only until it starts (a change that lands while the ML service is thinking queues the next
 * rebuild), and the text is built again before anything is written: if a review changed meanwhile, the result is dropped, as the next job covers it.
 * `review_count` is the number of qualifying reviews, not the number that fit in the text. Queue it through ReviewSummaries.
 */
class SummarizeReviews implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const MAX_CHARACTERS = 20000;

    public int $tries = 1;

    public function __construct(public readonly int $mediaId) {}

    public function uniqueId(): string
    {
        return (string) $this->mediaId;
    }

    public function handle(MlClient $ml): void
    {
        if (! Media::query()->whereKey($this->mediaId)->exists()) {
            return;
        }

        $source = $this->source();

        if ($source === null) {
            $this->clear();

            return;
        }

        if (ReviewSummary::query()->whereKey($this->mediaId)->where('source_hash', $source['hash'])->exists()) {
            return;
        }

        try {
            $summary = $ml->summarize($source['text'], 3);
        } catch (MlServiceUnavailable) {
            return;
        }

        // The reviews may have changed while the ML service worked (a hide, an edit, a deactivation): an answer about the old text is not written.
        if (($this->source()['hash'] ?? null) !== $source['hash']) {
            return;
        }

        if ($summary === null) {
            $this->clear();

            return;
        }

        ReviewSummary::query()->updateOrCreate(['media_id' => $this->mediaId], [
            'summary' => $summary,
            'review_count' => $source['count'],
            'source_hash' => $source['hash'],
            'generated_at' => now(),
        ]);
    }

    /**
     * The text to summarize and what it stands on, or null when fewer than the minimum number of reviews qualify.
     *
     * @return array{text: string, hash: string, count: int}|null
     */
    private function source(): ?array
    {
        $reviews = Review::query()
            ->where('media_id', $this->mediaId)
            ->where('status', ReviewState::Published->value)
            ->where('has_spoilers', false)
            ->whereHas('user', fn (Builder $author) => $author->active()->where('users.is_admin', false))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->pluck('body');

        if ($reviews->count() < (int) config('bookflix.social.review_summary_min_reviews')) {
            return null;
        }

        $included = [];
        $length = 0;

        foreach ($reviews as $body) {
            $next = $length + mb_strlen($body) + ($included === [] ? 0 : 2);

            if ($next > self::MAX_CHARACTERS) {
                break;
            }

            $included[] = $body;
            $length = $next;
        }

        $text = implode("\n\n", $included);

        return ['text' => $text, 'hash' => hash('sha256', $text), 'count' => $reviews->count()];
    }

    private function clear(): void
    {
        ReviewSummary::query()->whereKey($this->mediaId)->delete();
    }
}
