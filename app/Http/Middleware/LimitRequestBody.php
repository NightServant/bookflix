<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request body past a cap, in kilobytes, with 413. For the small JSON and beacon POST routes (a reading place, a few ids), whose bodies are
 * a few KB at most, so the controller never acts on an oversized body. (Laravel's global TrimStrings and ConvertEmptyStringsToNull middleware have already
 * decoded a JSON body by the time route middleware runs, so this stops the work that follows, not that first parse; `post_max_size` bounds that.) The declared Content-Length is checked first (nothing is read when it is already too
 * big); the real body length is checked too, because the header can be missing or understate it. Use as `LimitRequestBody::class.':8'`.
 */
class LimitRequestBody
{
    public function handle(Request $request, Closure $next, int|string $kilobytes = 8): Response
    {
        $limit = (int) $kilobytes * 1024;
        $declared = $request->headers->get('Content-Length');

        if (($declared !== null && ctype_digit($declared) && (int) $declared > $limit) || strlen($request->getContent()) > $limit) {
            abort(413, __('The request is too large.'));
        }

        return $next($request);
    }
}
