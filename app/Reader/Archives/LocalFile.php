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
     * Run `$callback` with the file's path on this machine; a copy made for it is removed when it returns.
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     *
     * @throws UnsafeArchive
     */
    public static function with(string $stored, callable $callback): mixed
    {
        $lease = self::lease($stored);

        try {
            return $callback($lease->path);
        } finally {
            $lease->release();
        }
    }

    /**
     * The file's path on this machine for as long as the caller keeps the lease: for a response that streams out of the file after the method that
     * opened it has returned. A disk that is not local is copied once, and the copy is removed by `release()` (or, if that is forgotten, when the
     * lease is garbage collected).
     *
     * @throws UnsafeArchive
     */
    public static function lease(string $stored): LocalFileLease
    {
        $disk = Storage::disk(ContentSource::disk());

        if ($stored === '' || ! $disk->exists($stored)) {
            throw UnsafeArchive::notAComic(__('The file is no longer available.'));
        }

        if ($disk instanceof LocalFilesystemAdapter) {
            return new LocalFileLease($disk->path($stored), false);
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
        } catch (\Throwable $exception) {
            @unlink($temp);

            throw $exception;
        }

        return new LocalFileLease($temp, true);
    }
}
