<?php

namespace App\Reader;

use App\Models\ContentSource;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * The only way a reader's browser gets to a file: a short-lived signed address bound to one reader. The signature is relative (no host), so the
 * address works behind any proxy; the `user` value is what ties it to a person, and the stream route checks it against who is signed in.
 */
class ContentUrl
{
    public static function for(User $user, ContentSource $source): string
    {
        return URL::temporarySignedRoute(
            'reader.stream',
            now()->addMinutes((int) config('bookflix.reader.url_ttl_minutes')),
            ['source' => $source->id, 'user' => $user->id],
            absolute: false,
        );
    }
}
