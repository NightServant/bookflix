<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

#[Signature('bookflix:prune-uploads')]
#[Description("Delete Livewire temporary uploads (the assistant's attached files) older than an hour")]
class PruneUploads extends Command
{
    /** Longer than `max_upload_time` (30 minutes), so a file still being attached is never removed. */
    private const MAX_AGE_SECONDS = 3600;

    public function handle(): int
    {
        $disk = Storage::disk(FileUploadConfiguration::disk());
        $cutoff = now()->subSeconds(self::MAX_AGE_SECONDS)->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles(FileUploadConfiguration::path()) as $path) {
            if ($disk->lastModified($path) < $cutoff) {
                $disk->delete($path);
                $deleted++;
            }
        }

        $this->info("Pruned {$deleted} temporary upload(s).");

        return self::SUCCESS;
    }
}
