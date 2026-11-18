<?php

namespace App\Http\Controllers\Reader;

use App\Http\Controllers\Controller;
use App\Models\ContentSource;
use App\Reader\ByteRangeResponse;
use App\Reader\ContentAccessGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets an admin open a file before verifying it. It is a separate signed route under /admin and never opens a reader's own upload; the gate's
 * `preview` rule decides. The response is the same as the reader's.
 */
class PreviewContentController extends Controller
{
    public function __invoke(Request $request, ContentAccessGate $gate, ContentSource $source): Response
    {
        // The signed `user` must be the admin signed in, so a copied address is of no use to anyone else.
        abort_unless((string) $request->query('user') === (string) $request->user()->id, 403);
        abort_unless($gate->preview($request->user(), $source), 403);

        if (! Storage::disk(ContentSource::disk())->exists((string) $source->storage_path)) {
            return response('This file is no longer available.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return ByteRangeResponse::make($request, $source);
    }
}
