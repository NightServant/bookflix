<?php

namespace App\Reader\Archives;

/**
 * A stored file as a path on this machine (see LocalFile::lease). When the path is a temporary copy, it is removed on `release()`.
 */
final class LocalFileLease
{
    private bool $released = false;

    public function __construct(public readonly string $path, private readonly bool $temporary) {}

    public function release(): void
    {
        if (! $this->released && $this->temporary) {
            @unlink($this->path);
        }

        $this->released = true;
    }

    public function __destruct()
    {
        $this->release();
    }
}
