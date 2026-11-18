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
 * Streams a reader's file. The address is signed and short-lived (see ContentUrl), but a signature alone is not trusted: the signed `user` must be the
 * person signed in, and the access gate is asked again now, so a source unverified after the address was issued stops at once. The path comes
 * from the source row, never from the request.
 */
class StreamContentController extends Controller
{
    public function __invoke(Request $request, ContentAccessGate $gate, ContentSource $source): Response
    {
        $user = $request->user();

        abort_unless((string) $request->query('user') === (string) $user->id, 403);
        abort_unless($gate->allows($user, $source), 403);

        if (! Storage::disk(ContentSource::disk())->exists((string) $source->storage_path)) {
            return response('This file is no longer available.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return ByteRangeResponse::make($request, $source);
    }
}
