<?php

namespace App\Reader\Cbz;

use App\Models\ContentSource;
use App\Reader\Archives\LocalFile;
use App\Reader\Archives\UnsafeArchive;
use App\Reader\Archives\ZipEntry;
use App\Reader\Archives\ZipGuard;
use Illuminate\Support\Facades\Cache;
use ZipArchive;

/**
 * Works out which entries of a stored comic zip are pages. Nothing is unpacked: the zip passes ZipGuard, then only the central directory is read.
 * The list is cached for a day, keyed by the stored file (path and checksum), so a page request does not walk the zip again and a replaced file
 * is never served from an old list.
 */
class CbzExtractor
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function __construct(private readonly ZipGuard $guard) {}

    /**
     * @throws UnsafeArchive
     */
    public function index(ContentSource $source): CbzPageIndex
    {
        $key = 'reader:cbz-index:'.$source->id.':'.hash('xxh128', $source->storage_path.'|'.$source->checksum);

        $cached = Cache::get($key);

        if (is_array($cached) && $cached !== []) {
            return new CbzPageIndex(array_map(
                fn (array $page): CbzPage => new CbzPage((int) $page['index'], (string) $page['name'], (int) $page['size']),
                array_values($cached),
            ));
        }

        $index = LocalFile::with((string) $source->storage_path, fn (string $path): CbzPageIndex => $this->indexFile($path));

        // Plain arrays, not the objects: `cache.serializable_classes` is false, so a store that serializes (redis, database) would hand objects back
        // as incomplete classes and the cache would never hit.
        Cache::put($key, array_map(fn (CbzPage $page): array => ['index' => $page->index, 'name' => $page->name, 'size' => $page->size], $index->pages), now()->addDay());

        return $index;
    }

    /**
     * Open the stored zip for reading and hand it to `$callback`.
     *
     * @template T
     *
     * @param  callable(ZipArchive): T  $callback
     * @return T
     *
     * @throws UnsafeArchive
     */
    public function open(ContentSource $source, callable $callback): mixed
    {
        return LocalFile::with((string) $source->storage_path, function (string $path) use ($callback) {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                throw UnsafeArchive::because(__('it is damaged or is not a zip.'));
            }

            try {
                return $callback($zip);
            } finally {
                $zip->close();
            }
        });
    }

    /**
     * The pages of a zip on this machine, in reading order. Public so an upload is judged by the very rules a stored comic is read with.
     *
     * @throws UnsafeArchive
     */
    public function indexFile(string $path): CbzPageIndex
    {
        $entries = array_filter($this->guard->inspect($path)->entries, $this->isPage(...));

        usort($entries, fn (ZipEntry $a, ZipEntry $b): int => strnatcasecmp($a->name, $b->name));

        if ($entries === []) {
            throw UnsafeArchive::notAComic(__('This comic has no pages.'));
        }

        return new CbzPageIndex(array_map(fn (ZipEntry $e): CbzPage => new CbzPage($e->index, $e->name, $e->size), $entries));
    }

    private function isPage(ZipEntry $entry): bool
    {
        $name = $entry->name;

        if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/')) {
            return false;
        }

        $base = basename($name);

        return ! str_starts_with($base, '.')
            && in_array(strtolower(pathinfo($base, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true);
    }
}
