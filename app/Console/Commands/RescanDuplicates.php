<?php

namespace App\Console\Commands;

use App\Catalog\Ingestion\Deduplicator;
use App\Enums\DuplicateStatus;
use App\Models\DuplicateCandidate;
use App\Models\Media;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookflix:rescan-duplicates')]
#[Description('Re-score every media against the catalogue and refresh the duplicate review queue; nothing is merged')]
class RescanDuplicates extends Command
{
    /**
     * Execute the console command. Every pair found is recorded or re-scored (a dismissed pair stays dismissed); a pending pair the scan
     * no longer finds has dropped below the review floor and leaves the queue.
     */
    public function handle(Deduplicator $deduplicator): int
    {
        $started = now()->startOfSecond();

        $bar = $this->output->createProgressBar(Media::query()->count());

        Media::query()->with(['creators', 'mediaType'])->lazyById(200)->each(function (Media $media) use ($deduplicator, $bar): void {
            $deduplicator->rescan($media);
            $bar->advance();
        });

        $bar->finish();
        $this->newLine();

        $pruned = DuplicateCandidate::query()->where('status', DuplicateStatus::Pending)->where('updated_at', '<', $started)->delete();
        $pending = DuplicateCandidate::query()->where('status', DuplicateStatus::Pending)->count();

        $this->info("{$pending} pairs await review; {$pruned} no longer similar enough were removed.");

        return self::SUCCESS;
    }
}
