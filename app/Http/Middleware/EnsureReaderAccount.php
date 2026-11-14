<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a signed-in admin out of the reader side: an admin account runs the catalog and has no library, collections, history or recommendations.
 * A page view is sent to the admin dashboard; anything that changes data answers 403 rather than a redirect, so a form post or a beacon never
 * looks like it worked. Readers and guests pass untouched. Put it after `auth` on every reader route (see routes/web.php and routes/settings.php).
 */
class EnsureReaderAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || Gate::forUser($user)->allows('use-reader-side')) {
            return $next($request);
        }

        abort_unless($request->isMethodSafe(), 403);

        return redirect()->route('admin.index');
    }
}
