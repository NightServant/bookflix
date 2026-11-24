<?php

namespace App\Console\Commands;

use App\Ai\Summaries\LlmSummarizer;
use App\Jobs\GenerateAiSummary;
use App\Models\Media;
use App\Models\MediaAiSummary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookflix:ai-summaries {--limit=100 : The most stories to queue} {--stale : Only list summaries written by an older prompt or model version}')]
#[Description('Queue AI summaries for stories that lack an up-to-date one, or list the stale ones')]
class AiSummaries extends Command
{
    public function handle(LlmSummarizer $summarizer): int
    {
        $version = $summarizer->modelVersion();

        if ($this->option('stale')) {
            $stale = MediaAiSummary::query()->with('modelVersion', 'media:id,title')
                ->where(fn ($query) => $query->whereNull('model_version_id')->orWhere('model_version_id', '!=', $version->id))
                ->orderBy('media_id')->get();

            $this->table(['Media', 'Title', 'Version'], $stale->map(fn (MediaAiSummary $row): array => [$row->media_id, $row->media?->title, $row->modelVersion->version ?? '-'])->all());
            $this->info("Current version: {$version->version}");

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $queued = 0;

        // ponytail: the description hash is computed in SQL; fine at catalogue scale for a manual command.
        $upToDate = fn ($query) => $query->where('model_version_id', $version->id)
            ->whereRaw("media_ai_summaries.source_hash = encode(sha256(convert_to(media.description, 'UTF8')), 'hex')");

        Media::query()
            ->whereRaw('char_length(description) >= ?', [GenerateAiSummary::MIN_CHARACTERS])
            ->whereDoesntHave('aiSummary', $upToDate)
            ->orderBy('id')
            ->lazyById()
            ->takeWhile(fn () => $queued < $limit)
            ->each(function (Media $media) use (&$queued): void {
                if (GenerateAiSummary::eligible($media)) {
                    GenerateAiSummary::dispatch($media->id);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} summaries.");

        return self::SUCCESS;
    }
}
