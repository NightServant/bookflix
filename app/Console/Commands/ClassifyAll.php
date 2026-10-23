<?php

namespace App\Console\Commands;

use App\Jobs\ClassifyMedia;
use App\Models\Media;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookflix:classify-all')]
#[Description('Queue a classification job for every media')]
class ClassifyAll extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queued = 0;

        foreach (Media::select('id')->lazyById(500) as $media) {
            ClassifyMedia::dispatch($media->id);
            $queued++;
        }

        $this->info("Queued {$queued} classification job(s).");

        return self::SUCCESS;
    }
}
