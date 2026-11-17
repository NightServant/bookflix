<?php

namespace App\Jobs;

use App\Enums\ContentFormat;
use App\Models\ContentSource;
use App\Reader\Cbz\CbrConverter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Converts a stored CBR into a CBZ in the background. It does nothing once the source is no longer a CBR with a file, so running it twice is
 * harmless. It is not retried: a file that failed to convert fails the same way again, and the reason is on the source.
 */
class ConvertCbrToCbz implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $contentSourceId)
    {
        $this->onQueue('ingestion');
    }

    /**
     * One conversion per source at a time.
     */
    public function uniqueId(): string
    {
        return (string) $this->contentSourceId;
    }

    public function handle(CbrConverter $converter): void
    {
        $source = ContentSource::find($this->contentSourceId);

        if ($source === null || $source->format !== ContentFormat::Cbr || $source->storage_path === null) {
            return;
        }

        $converter->convert($source);
    }
}
