<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request body past a cap, in kilobytes, with 413. For the small JSON and beacon POST routes (a reading place, a few ids), whose bodies are
 * a few KB at most, so a client cannot make them parse a megabyte. The declared Content-Length is checked first (nothing is read when it is already too
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
