<?php

namespace App\Console\Commands;

use App\Jobs\ClassifyMedia;
use App\Models\Media;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('bookflix:classify-all {--only-missing : Skip media that already have a live primary class}')]
#[Description('Queue a classification job for every media')]
class ClassifyAll extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queued = 0;
        $query = Media::select('id')->when($this->option('only-missing'), fn (Builder $media) => $media->whereDoesntHave('primaryDewey'));

        foreach ($query->lazyById(500) as $media) {
            ClassifyMedia::dispatch($media->id);
            $queued++;
        }

        $this->info("Queued {$queued} classification job(s).");

        return self::SUCCESS;
    }
}
