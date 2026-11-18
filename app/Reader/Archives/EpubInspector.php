<?php

namespace App\Reader\Archives;

use ZipArchive;

/**
 * Checks that a file is really an EPUB (the OCF container rules) on top of being a safe zip: the first entry is a stored `mimetype` that says
 * `application/epub+zip`, and the container manifest exists. An HTML page or an arbitrary zip renamed to .epub fails here.
 */
class EpubInspector
{
    public function __construct(private readonly ZipGuard $guard) {}

    /**
     * @throws UnsafeArchive
     */
    public function check(string $path): void
    {
        $inventory = $this->guard->inspect($path);
        $first = $inventory->first();

        if ($first->name !== 'mimetype' || $inventory->find('META-INF/container.xml') === null) {
            throw new UnsafeArchive(__('This file is not a valid EPUB.'));
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw UnsafeArchive::because(__('it is damaged or is not a zip.'));
        }

        try {
            $stat = $zip->statIndex($first->index);

            if ($stat === false || $stat['comp_method'] !== ZipArchive::CM_STORE || $first->size > 64) {
                throw new UnsafeArchive(__('This file is not a valid EPUB.'));
            }

            if ($this->guard->readEntry($zip, $first->index, 64) !== 'application/epub+zip') {
                throw new UnsafeArchive(__('This file is not a valid EPUB.'));
            }
        } finally {
            $zip->close();
        }
    }
}
