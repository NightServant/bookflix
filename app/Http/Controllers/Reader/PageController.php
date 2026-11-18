<?php

namespace App\Http\Controllers\Reader;

use App\Enums\ContentFormat;
use App\Http\Controllers\Controller;
use App\Models\ContentSource;
use App\Reader\Archives\UnsafeArchive;
use App\Reader\Cbz\CbzExtractor;
use App\Reader\Cbz\CbzPage;
use App\Reader\ContentAccessGate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Serves one page of a comic. Like the stream route, the address is signed but not trusted on its own: the signed `user` must be the person signed
 * in and the access gate is asked again. The image is streamed out of the zip in chunks (never extracted to disk, never held whole in memory), from the one open archive of the request: a disk that is not local is copied once, and the copy goes when the stream ends. A page
 * whose declared size is above `reader.archive.max_page_mb` is refused, the first 64 KB name its type from its own bytes (an entry named `.jpg`
 * that holds HTML is refused, not served), and the stream stops at the declared size.
 */
class PageController extends Controller
{
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** How much of a page is read to name its type. */
    private const SNIFF_BYTES = 65536;

    private const CHUNK_BYTES = 65536;

    public function __invoke(Request $request, ContentAccessGate $gate, CbzExtractor $extractor, ContentSource $source, int $page): Response
    {
        $user = $request->user();

        abort_unless((string) $request->query('user') === (string) $user->id, 403);
        abort_unless($gate->allows($user, $source), 403);
        abort_unless($source->format === ContentFormat::Cbz, 404);

        // The archive is opened once: sniffed here, streamed from the same open zip below.
        $opened = null;

        try {
            $entry = $extractor->index($source)->page($page);
            abort_if($entry === null, 404);

            $opened = $extractor->openLeased($source);
            [$type, $size] = $this->sniff($opened->zip, $entry);
        } catch (UnsafeArchive) {
            $opened?->close();

            return $this->unavailable();
        }

        if ($type === null) {
            $opened->close();

            return $this->unavailable();
        }

        $headers = [
            'Content-Type' => $type,
            'Content-Length' => (string) $size,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        // The open zip outlives this method; it (and a temporary copy from a disk that is not local) is closed when the stream ends.
        return new StreamedResponse(function () use ($opened, $entry, $size): void {
            try {
                $this->copy($opened->zip, $entry, $size);
            } catch (UnsafeArchive) {
                // The headers are out already: the reader's browser gets a broken image, nothing more can be said.
            } finally {
                $opened->close();
            }
        }, 200, $headers);
    }

    /**
     * Look at the first 64 KB of the entry to name its type, without reading the rest. A page whose declared size is above the cap is refused here,
     * before a byte is sent. Returns the type (null when it is not an image) and the declared size.
     *
     * @return array{0: string|null, 1: int}
     *
     * @throws UnsafeArchive
     */
    private function sniff(ZipArchive $zip, CbzPage $entry): array
    {
        $stat = $this->stat($zip, $entry);
        $size = (int) $stat['size'];

        if ($size > (int) config('bookflix.reader.archive.max_page_mb') * 1048576) {
            throw UnsafeArchive::because(__('a page is larger than this server serves.'));
        }

        $stream = $zip->getStreamIndex($entry->index);

        if ($stream === false) {
            throw UnsafeArchive::because(__('an entry could not be read.'));
        }

        try {
            $head = '';

            while (strlen($head) < self::SNIFF_BYTES && ! feof($stream)) {
                $chunk = fread($stream, self::SNIFF_BYTES - strlen($head));

                if ($chunk === false) {
                    throw UnsafeArchive::because(__('an entry could not be read.'));
                }

                $head .= $chunk;
            }
        } finally {
            fclose($stream);
        }

        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($head);

        return [in_array($type, self::IMAGE_TYPES, true) ? $type : null, $size];
    }

    /**
     * Send the entry in chunks, never holding more than one in memory, and stop at the declared size even if the archive holds more.
     *
     * @throws UnsafeArchive
     */
    private function copy(ZipArchive $zip, CbzPage $entry, int $size): void
    {
        $this->stat($zip, $entry);
        $stream = $zip->getStreamIndex($entry->index);

        if ($stream === false) {
            throw UnsafeArchive::because(__('an entry could not be read.'));
        }

        try {
            $sent = 0;

            while ($sent < $size && ! feof($stream)) {
                $chunk = fread($stream, max(1, min(self::CHUNK_BYTES, $size - $sent)));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                echo $chunk;
                flush();
                $sent += strlen($chunk);
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws UnsafeArchive
     */
    private function stat(ZipArchive $zip, CbzPage $entry): array
    {
        $stat = $zip->statIndex($entry->index);

        // The index may be a day old: if the entry at that position is no longer the page it described, the file was replaced.
        if ($stat === false || $stat['name'] !== $entry->name) {
            throw UnsafeArchive::because(__('an entry could not be found.'));
        }

        return $stat;
    }

    private function unavailable(): Response
    {
        return response('This page is not available.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
