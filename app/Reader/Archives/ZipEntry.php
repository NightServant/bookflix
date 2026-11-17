<?php

namespace App\Reader\Archives;

/**
 * One file in a zip's central directory, as the archive declares it (the sizes are checked again while reading).
 */
final readonly class ZipEntry
{
    public function __construct(
        public string $name,
        public int $size,
        public int $compressedSize,
        public int $index,
    ) {}
}
