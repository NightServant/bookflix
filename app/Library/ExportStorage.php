<?php

namespace App\Library;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Where the data exports live and how a reader gets to one: `exports/{userId}/{ulid}.zip` on a private disk (see config `bookflix.exports`),
 * reached only through the signed `exports.download` route. The job that writes them, the download, the page that shows their state, the prune
 * command and the account purge all go through here, so the disk rule and the path shape are in one place.
 */
class ExportStorage
{
    /** The shape of an export's name: a ULID. The download route holds the same rule, so no other string ever becomes part of a path. */
    public const FILE_PATTERN = '[0-9A-Z]{26}';

    /**
     * The private disk. A disk that serves its files to the web is refused: the zip holds a reader's whole history.
     *
     * @throws RuntimeException when the configured disk is public
     */
    public function disk(): Filesystem
    {
        $name = $this->diskName();

        if ($name === 'public' || $name === config('bookflix.avatar_disk') || config("filesystems.disks.{$name}.visibility") === 'public') {
            throw new RuntimeException("The data export disk [{$name}] is public; exports need a private disk.");
        }

        return Storage::disk($name);
    }

    private function diskName(): string
    {
        return (string) config('bookflix.exports.disk');
    }

    public function directory(int $userId): string
    {
        return "exports/{$userId}";
    }

    public function path(int $userId, string $file): string
    {
        return $this->directory($userId)."/{$file}.zip";
    }

    /**
     * The link the reader is mailed (and the privacy page shows): signed, owner-only and short-lived.
     */
    public function link(int $userId, string $file): string
    {
        return URL::temporarySignedRoute('exports.download', now()->addHours((int) config('bookflix.exports.link_hours')), ['user' => $userId, 'file' => $file]);
    }

    /**
     * The reader's newest export still on disk, or null.
     *
     * @return array{file: string, at: Carbon}|null
     */
    public function latest(int $userId): ?array
    {
        $disk = $this->disk();
        $latest = null;

        foreach ($disk->files($this->directory($userId)) as $path) {
            $file = pathinfo($path, PATHINFO_FILENAME);

            if (pathinfo($path, PATHINFO_EXTENSION) !== 'zip' || ! preg_match('/^'.self::FILE_PATTERN.'$/', $file)) {
                continue;
            }

            $at = Carbon::createFromTimestamp($disk->lastModified($path));

            if ($latest === null || $at->greaterThan($latest['at'])) {
                $latest = ['file' => $file, 'at' => $at];
            }
        }

        return $latest;
    }

    /**
     * Delete the reader's exports, all of them or all but one. Unlike reading and writing, this does not insist on a private disk: clearing files
     * from a disk that turned out to be public is what we want, and the purge of an account must never fail on it.
     */
    public function clear(int $userId, ?string $keep = null): void
    {
        $disk = Storage::disk($this->diskName());

        foreach ($disk->files($this->directory($userId)) as $path) {
            if ($keep === null || $path !== $this->path($userId, $keep)) {
                $disk->delete($path);
            }
        }

        if ($keep === null) {
            $disk->deleteDirectory($this->directory($userId));
        }
    }

    /**
     * Delete the exports older than the retention period, and the folders they leave empty. Returns how many files went.
     */
    public function prune(): int
    {
        $disk = Storage::disk($this->diskName());
        $cutoff = now()->subDays((int) config('bookflix.exports.retention_days'))->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles('exports') as $path) {
            if ($disk->lastModified($path) < $cutoff) {
                $disk->delete($path);
                $deleted++;
            }
        }

        foreach ($disk->directories('exports') as $directory) {
            if ($disk->allFiles($directory) === []) {
                $disk->deleteDirectory($directory);
            }
        }

        return $deleted;
    }
}
