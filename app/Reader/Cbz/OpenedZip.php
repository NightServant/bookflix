<?php

namespace App\Reader\Cbz;

use App\Reader\Archives\LocalFileLease;
use ZipArchive;

/**
 * A stored zip kept open beyond the method that opened it (a response that streams from it). `close()` closes the zip and removes the temporary copy a
 * disk that is not local needed.
 */
final class OpenedZip
{
    private bool $closed = false;

    public function __construct(public readonly ZipArchive $zip, private readonly LocalFileLease $lease) {}

    public function close(): void
    {
        if (! $this->closed) {
            @$this->zip->close();
            $this->lease->release();
        }

        $this->closed = true;
    }

    public function __destruct()
    {
        $this->close();
    }
}
