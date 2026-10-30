<?php

namespace App\Http\Controllers;

use App\Library\ExportStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands a reader their own data export. The route is signed (so it expires), needs a signed-in reader, and the reader must be the one the export
 * belongs to. The path is built from the integer user id and a file name the route already limited to a ULID, never from anything else the request
 * carries, so it cannot point outside `exports/{id}/`. A file that is gone (pruned, or replaced by a newer export) is a 404.
 */
class ExportDownloadController extends Controller
{
    public function show(Request $request, ExportStorage $exports, string $user, string $file): StreamedResponse
    {
        abort_unless($request->user()->id === (int) $user, 403);

        $disk = $exports->disk();
        $path = $exports->path((int) $user, $file);

        abort_unless($disk->exists($path), 404);

        return $disk->download($path, 'bookflix-export-'.now()->format('Y-m-d').'.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
