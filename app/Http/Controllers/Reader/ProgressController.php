<?php

namespace App\Http\Controllers\Reader;

use App\Http\Controllers\Controller;
use App\Models\ContentSource;
use App\Reader\ContentAccessGate;
use App\Reader\ProgressWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Where the reader's browser saves its place. The body is JSON, or a form field `payload` holding the JSON, which is the only shape
 * `navigator.sendBeacon` can send when the page is closing. The access gate is asked on every save: a 403 is how the page learns the file was withdrawn.
 * Answers are JSON whatever the client asked for, since a beacon never reads them.
 */
class ProgressController extends Controller
{
    public function __invoke(Request $request, ContentAccessGate $gate, ProgressWriter $writer, ContentSource $source): Response
    {
        $request->headers->set('Accept', 'application/json');

        $user = $request->user();

        abort_unless($gate->allows($user, $source), 403);

        $payload = $request->input('payload');

        if (is_string($payload)) {
            $payload = json_decode($payload, true);

            is_array($payload) || throw ValidationException::withMessages(['payload' => __('The progress could not be read.')]);
        } else {
            $payload = $request->json()->all();
        }

        $writer->write($user, $source, $payload);

        return response()->noContent();
    }
}
