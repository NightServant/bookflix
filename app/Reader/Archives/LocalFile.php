<?php

namespace App\Reader\Archives;

use App\Models\ContentSource;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * A stored content file as a path on this machine, for the readers and converters that need a real file (ZipArchive, an unpacking program). A
 * disk that is not local is copied to a temporary file first, which is removed once the callback returns.
 */
class LocalFile
{
    /**
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     *
     * @throws UnsafeArchive
     */
    public static function with(string $stored, callable $callback): mixed
    {
        $disk = Storage::disk(ContentSource::disk());

        if ($stored === '' || ! $disk->exists($stored)) {
            throw UnsafeArchive::notAComic(__('The file is no longer available.'));
        }

        if ($disk instanceof LocalFilesystemAdapter) {
            return $callback($disk->path($stored));
        }

        $temp = (string) tempnam(sys_get_temp_dir(), 'bookflix-local-');

        try {
            $stream = $disk->readStream($stored);
            $target = fopen($temp, 'wb');

            if (! is_resource($stream) || $target === false) {
                throw UnsafeArchive::notAComic(__('The file could not be read.'));
            }

            stream_copy_to_stream($stream, $target);
            fclose($stream);
            fclose($target);

            return $callback($temp);
        } finally {
            @unlink($temp);
        }
    }
}
