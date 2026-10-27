<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('bookflix:prune-covers {--days=30 : Delete cached covers older than this many days}')]
#[Description('Delete cached proxy covers that have not been fetched for a while (a changed cover URL leaves its old file behind)')]
class PruneCovers extends Command
{
    public function handle(): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDays((int) $this->option('days'))->getTimestamp();
        $pruned = 0;

        foreach ($disk->files('covers') as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $pruned++;
            }
        }

        $this->info("Pruned {$pruned} cached cover(s).");

        return self::SUCCESS;
    }
}
