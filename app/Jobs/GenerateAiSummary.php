<?php

namespace App\Jobs;

use App\Ai\LlmUnavailable;
use App\Ai\Summaries\LlmSummarizer;
use App\Catalog\Ingestion\ProhibitedContent;
use App\Models\Media;
use App\Models\MediaAiSummary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Write the model's summary of one story's description (P11-R5), on the `ai` queue. Nothing happens when AI is off, for a description under
 * `MIN_CHARACTERS`, for a title or description that ProhibitedContent matches (P11-R10), or when the stored summary already stands on this
 * description and this prompt+model version. A model that cannot answer or answers badly leaves everything as it is: the extractive summary stays.
 */
class GenerateAiSummary implements ShouldQueue
{
    use Queueable;

    public const MIN_CHARACTERS = 800;

    public int $tries = 2;

    public function __construct(public readonly int $mediaId)
    {
        $this->onQueue('ai');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('ai-summaries'), (new WithoutOverlapping((string) $this->mediaId))->releaseAfter(30)->expireAfter(300)];
    }

    public static function eligible(Media $media): bool
    {
        $description = (string) $media->description;

        return mb_strlen($description) >= self::MIN_CHARACTERS
            && ! ProhibitedContent::matches($media->title)
            && ! ProhibitedContent::matches($description);
    }

    public function handle(LlmSummarizer $summarizer): void
    {
        if (! config('bookflix.ai.enabled')) {
            return;
        }

        $media = Media::query()->find($this->mediaId);

        if ($media === null || ! self::eligible($media)) {
            return;
        }

        $hash = hash('sha256', $media->description);
        $version = $summarizer->modelVersion();

        $current = MediaAiSummary::query()->whereKey($media->id)->where('source_hash', $hash)->where('model_version_id', $version->id)->exists();

        if ($current) {
            return;
        }

        try {
            $summary = $summarizer->summarize($media->description);
        } catch (LlmUnavailable) {
            return;
        }

        MediaAiSummary::query()->updateOrCreate(['media_id' => $media->id], [
            'summary' => $summary,
            'source_hash' => $hash,
            'model_version_id' => $version->id,
            'generated_at' => now(),
        ]);
    }
}
