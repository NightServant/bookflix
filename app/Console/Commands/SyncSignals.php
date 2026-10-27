<?php

namespace App\Console\Commands;

use App\Catalog\Popularity\PopularityScorer;
use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Catalog\Signals\WikidataLinker;
use App\Catalog\Signals\WikipediaPageviews;
use App\Models\ExternalSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookflix:sync-signals {source? : wikidata or wikipedia, both when omitted} {--batch= : Media to check per source (default from config)} {--force : Run even though the source is disabled}')]
#[Description('Link media to Wikidata and record Wikipedia pageviews as popularity signals, then rescore popularity')]
class SyncSignals extends Command
{
    /**
     * Signal sources never create media. Wikidata runs first so newly linked articles get their pageviews in the same run.
     * A disabled source is skipped unless `--force` is given.
     */
    public function handle(WikidataLinker $wikidata, WikipediaPageviews $wikipedia, PopularityScorer $scorer): int
    {
        $only = $this->argument('source');
        $sources = ['wikidata' => $wikidata->run(...), 'wikipedia' => $wikipedia->run(...)];

        if ($only !== null && (! is_string($only) || ! isset($sources[$only]))) {
            $this->error('Unknown signal source. Known: '.implode(', ', array_keys($sources)).'.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($sources as $key => $run) {
            if (($only !== null && $only !== $key) || (! $this->option('force') && ! ExternalSource::where('key', $key)->where('enabled', true)->exists())) {
                continue;
            }

            $batch = max(1, (int) ($this->option('batch') ?: config("bookflix.signals.{$key}_batch")));

            try {
                $this->info("{$key}: ".json_encode($run($batch)));
            } catch (RateLimitedByProvider $exception) {
                $this->warn("{$key} asked us to slow down; retry in {$exception->retryAfter}s.");
                $failed = true;
            } catch (ProviderUnavailable $exception) {
                $this->error("{$key} is unavailable: {$exception->getMessage()}");
                $failed = true;
            }
        }

        $this->info("Scored {$scorer->run()} media.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
