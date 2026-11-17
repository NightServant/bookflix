<?php

namespace App\Http\Controllers\Reader;

use App\Enums\ContentFormat;
use App\Http\Controllers\Controller;
use App\Models\ContentSource;
use App\Reader\Archives\UnsafeArchive;
use App\Reader\Archives\ZipGuard;
use App\Reader\Cbz\CbzExtractor;
use App\Reader\Cbz\CbzPage;
use App\Reader\ContentAccessGate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * Serves one page of a comic. Like the stream route, the address is signed but not trusted on its own: the signed `user` must be the person signed
 * in and the access gate is asked again. The image is read out of the zip in memory (never extracted to disk) with the size the archive declares as
 * the cap, and its type comes from its own bytes: an entry named `.jpg` that holds HTML is refused, not served.
 */
class PageController extends Controller
{
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __invoke(Request $request, ContentAccessGate $gate, CbzExtractor $extractor, ZipGuard $guard, ContentSource $source, int $page): Response
    {
        $user = $request->user();

        abort_unless((string) $request->query('user') === (string) $user->id, 403);
        abort_unless($gate->allows($user, $source), 403);
        abort_unless($source->format === ContentFormat::Cbz, 404);

        try {
            $entry = $extractor->index($source)->page($page);
            abort_if($entry === null, 404);

            $bytes = $extractor->open($source, fn (ZipArchive $zip): string => $this->read($zip, $guard, $entry));
        } catch (UnsafeArchive) {
            return $this->unavailable();
        }

        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! in_array($type, self::IMAGE_TYPES, true)) {
            return $this->unavailable();
        }

        return new Response($bytes, 200, [
            'Content-Type' => $type,
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * @throws UnsafeArchive
     */
    private function read(ZipArchive $zip, ZipGuard $guard, CbzPage $entry): string
    {
        $stat = $zip->statIndex($entry->index);

        // The index may be a day old: if the entry at that position is no longer the page it described, the file was replaced.
        if ($stat === false || $stat['name'] !== $entry->name) {
            throw UnsafeArchive::because(__('an entry could not be found.'));
        }

        return $guard->readEntry($zip, $entry->index, $entry->size);
    }

    private function unavailable(): Response
    {
        return response('This page is not available.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
