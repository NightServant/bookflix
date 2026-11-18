<?php

namespace App\Reader\Archives;

use ZipArchive;

/**
 * The one gate every zip passes before Bookflix reads it (EPUB, CBZ and converted CBR). It reads only the central directory, so nothing is
 * unpacked here, and refuses what is dangerous: paths that climb out of the folder (zip-slip), symlinks, too many entries, and archives that
 * unpack far larger than they are (zip bombs). The central directory can lie about sizes, so `readEntry` counts the real bytes as it streams.
 */
class ZipGuard
{
    /** An entry or archive smaller than this is not judged by its ratio: a few kilobytes of repeated text compress well without being a bomb. */
    private const RATIO_FLOOR_BYTES = 1048576;

    /**
     * @throws UnsafeArchive
     */
    public function inspect(string $path): ZipInventory
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw UnsafeArchive::because(__('it is damaged or is not a zip.'));
        }

        try {
            return $this->inventory($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * Read one entry by stream and stop as soon as it grows past what the archive declared or the cap, whichever is smaller.
     *
     * @throws UnsafeArchive
     */
    public function readEntry(ZipArchive $zip, int $index, int $maxBytes): string
    {
        $stat = $zip->statIndex($index);

        if ($stat === false) {
            throw UnsafeArchive::because(__('an entry could not be found.'));
        }

        $limit = min((int) $stat['size'], $maxBytes);
        $stream = $zip->getStreamIndex($index);

        if ($stream === false) {
            throw UnsafeArchive::because(__('an entry could not be read.'));
        }

        $data = '';

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);

                if ($chunk === false) {
                    throw UnsafeArchive::because(__('an entry could not be read.'));
                }

                $data .= $chunk;

                if (strlen($data) > $limit) {
                    throw UnsafeArchive::because(__('an entry is larger than the archive says.'));
                }
            }
        } finally {
            fclose($stream);
        }

        return $data;
    }

    /**
     * @throws UnsafeArchive
     */
    private function inventory(ZipArchive $zip): ZipInventory
    {
        $count = $zip->numFiles;

        if ($count === 0) {
            throw UnsafeArchive::because(__('it is empty.'));
        }

        if ($count > (int) config('bookflix.reader.archive.max_entries')) {
            throw UnsafeArchive::because(__('it holds too many files.'));
        }

        $maxRatio = (int) config('bookflix.reader.archive.max_ratio');
        $maxBytes = (int) config('bookflix.reader.archive.max_uncompressed_mb') * 1048576;
        $entries = [];
        $total = 0;
        $compressedTotal = 0;

        for ($index = 0; $index < $count; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                throw UnsafeArchive::because(__('it is damaged.'));
            }

            $name = (string) $stat['name'];
            $size = (int) $stat['size'];
            $compressed = (int) $stat['comp_size'];

            self::checkName($name);
            $this->checkNotSymlink($zip, $index);

            if ($size > self::RATIO_FLOOR_BYTES && $size / max($compressed, 1) > $maxRatio) {
                throw UnsafeArchive::because(__('it unpacks far larger than it is.'));
            }

            $total += $size;
            $compressedTotal += $compressed;

            if ($total > $maxBytes) {
                throw UnsafeArchive::because(__('it unpacks to too much data.'));
            }

            $entries[] = new ZipEntry($name, $size, $compressed, $index);
        }

        if ($total > self::RATIO_FLOOR_BYTES && $total / max($compressedTotal, 1) > $maxRatio) {
            throw UnsafeArchive::because(__('it unpacks far larger than it is.'));
        }

        return new ZipInventory($entries);
    }

    /**
     * Refuse a name that could climb out of its folder. Public and static because a RAR listing is held to the very same rule.
     *
     * @throws UnsafeArchive
     */
    public static function checkName(string $name): void
    {
        $unsafe = $name === ''
            || str_contains($name, "\0")
            || str_contains($name, '\\')
            || str_starts_with($name, '/')
            || preg_match('/^[A-Za-z]:/', $name) === 1
            || in_array('..', explode('/', $name), true);

        if ($unsafe) {
            throw UnsafeArchive::because(__('it holds a file with an unsafe name.'));
        }
    }

    /**
     * @throws UnsafeArchive
     */
    private function checkNotSymlink(ZipArchive $zip, int $index): void
    {
        $opsys = 0;
        $attributes = 0;

        if ($zip->getExternalAttributesIndex($index, $opsys, $attributes) && $opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
            throw UnsafeArchive::because(__('it holds a link.'));
        }
    }
}
