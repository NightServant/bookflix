<?php

namespace App\Jobs;

use App\Classification\ClassificationService;
use App\Models\Media;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ClassifyMedia implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $mediaId,
        public ?string $externalCode = null,
    ) {
        $this->onQueue('ingestion');
    }

    /**
     * Classify one media. A media deleted or merged away since the job was queued is skipped.
     */
    public function handle(ClassificationService $classification): void
    {
        $media = Media::find($this->mediaId);

        if ($media !== null) {
            $classification->apply($media, $this->externalCode);
        }
    }
}
