<?php

namespace App\Reader\Cbz;

/**
 * One page of a comic: where its image sits in the zip, its path inside the archive and the size the archive declares for it.
 */
final readonly class CbzPage
{
    public function __construct(
        public int $index,
        public string $name,
        public int $size,
    ) {}
}
