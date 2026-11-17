<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page-wide Content Security Policy (P9-R9). Scripts run only from this site or with this request's nonce, which Livewire, Flux and the head
 * snippet read from Vite. `'unsafe-eval'` stays because Alpine's standard build evaluates its expressions. Nothing may frame the site, and the
 * reader's file iframe is same-origin or a blob. A response that already carries a policy (the file stream's own) keeps it.
 */
class SetContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('bookflix.csp.enabled')) {
            return $next($request);
        }

        // Before the response is built, so every view that asks for the nonce gets the same one.
        Vite::useCspNonce();

        $response = $next($request);

        if ($response->headers->has('Content-Security-Policy') || $response->headers->has('Content-Security-Policy-Report-Only')) {
            return $response;
        }

        // The local error page runs inline scripts of its own; a policy would leave it blank.
        if (config('app.debug') && property_exists($response, 'exception') && $response->exception !== null) {
            return $response;
        }

        $header = config('bookflix.csp.report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        $response->headers->set($header, $this->policy(Vite::cspNonce()));

        return $response;
    }

    private function policy(string $nonce): string
    {
        $dev = $this->devServer();
        $script = "'self' 'nonce-{$nonce}' 'unsafe-eval'".$dev['http'];

        return implode('; ', [
            "default-src 'self'",
            "script-src {$script}",
            "style-src 'self' 'unsafe-inline'".$dev['http'],
            "img-src 'self' data: blob: https:".$dev['http'],
            "font-src 'self' data:".$dev['http'],
            "connect-src 'self'".$dev['http'].$dev['socket'],
            "media-src 'self'",
            "worker-src 'self' blob:",
            "frame-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /**
     * With the Vite dev server running (local only) its origin must be allowed too, with the socket form for hot reload.
     *
     * @return array{http: string, socket: string}
     */
    private function devServer(): array
    {
        $none = ['http' => '', 'socket' => ''];

        if (! Vite::isRunningHot()) {
            return $none;
        }

        $origin = parse_url(trim((string) file_get_contents(Vite::hotFile())));

        if (! is_array($origin) || ! isset($origin['scheme'], $origin['host'])) {
            return $none;
        }

        $authority = $origin['host'].(isset($origin['port']) ? ':'.$origin['port'] : '');
        $socket = $origin['scheme'] === 'https' ? 'wss' : 'ws';

        return ['http' => " {$origin['scheme']}://{$authority}", 'socket' => " {$socket}://{$authority}"];
    }
}
