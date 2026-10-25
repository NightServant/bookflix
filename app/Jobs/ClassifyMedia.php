<?php

namespace App\Jobs;

use App\Classification\ClassificationService;
use App\Models\Media;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ClassifyMedia implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public function __construct(public int $mediaId)
    {
        $this->onQueue('ingestion');
    }

    /**
     * One waiting classification per media. The lock lifts when the job starts, so a dispatch made while it runs
     * (an import that just stored a new external id) still queues a run that sees it.
     */
    public function uniqueId(): string
    {
        return (string) $this->mediaId;
    }

    /**
     * Classify one media. A media deleted or merged away since the job was queued is skipped.
     */
    public function handle(ClassificationService $classification): void
    {
        $media = Media::find($this->mediaId);

        if ($media !== null) {
            $classification->apply($media);
        }
    }
}
