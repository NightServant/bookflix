<?php

namespace App\Reader\Cbz;

use App\Models\ContentSource;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * The signed address of one comic page, made the same way as ContentUrl: relative, short-lived and bound to one reader through the signed `user`.
 */
class CbzPageUrl
{
    public static function for(User $user, ContentSource $source, int $page): string
    {
        return URL::temporarySignedRoute(
            'reader.page',
            now()->addMinutes((int) config('bookflix.reader.url_ttl_minutes')),
            ['source' => $source->id, 'page' => $page, 'user' => $user->id],
            absolute: false,
        );
    }
}
