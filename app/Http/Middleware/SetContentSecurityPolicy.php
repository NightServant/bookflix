<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page-wide Content Security Policy (P9-R9). Scripts run only from this site or with this request's nonce, which Livewire, Flux and the head
 * snippet read from Vite. `'unsafe-eval'` stays because Alpine's standard build evaluates its expressions. Nothing may frame the site, and the
 * reader's file iframe is same-origin or a blob. A response that already carries a policy (the file stream's own) keeps it. The reader page alone also allows `blob:` styles and fonts (P9-R17), and takes no remote images.
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
        $response->headers->set($header, $this->policy(Vite::cspNonce(), $request->routeIs('reader.show')));

        return $response;
    }

    private function policy(string $nonce, bool $reader = false): string
    {
        $dev = $this->devServer();
        $script = "'self' 'nonce-{$nonce}' 'unsafe-eval'".$dev['http'];
        // The reader page only (P9-R17): an EPUB's stylesheets and fonts are blob URLs inside its sandboxed iframe, which inherits this policy.
        $blob = $reader ? ' blob:' : '';
        // The reader page's images are the book's own (a blob from the EPUB, or the signed page route): a remote image in a book must not reach another host.
        $images = $reader ? "'self' data: blob:" : "'self' data: blob: https:";

        return implode('; ', [
            "default-src 'self'",
            "script-src {$script}",
            "style-src 'self' 'unsafe-inline'{$blob}".$dev['http'],
            "img-src {$images}".$dev['http'],
            "font-src 'self' data:{$blob}".$dev['http'],
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
