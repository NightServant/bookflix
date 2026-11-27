<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // `same-origin`: other sites get no Referer at all. Hotlink-protected cover hosts (MangaDex) answer a foreign Referer with a
        // placeholder image, and the CSS backgrounds of the 3D books can't carry a per-image referrerpolicy.
        $response->headers->add([
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'X-Frame-Options' => 'DENY',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ]);

        // Only over https: a browser ignores it on plain http, and a local http run should not pin the host. (No TrustProxies is configured, so behind
        // a TLS-terminating proxy this stays off until the proxy list is set; see the Phase 1 ledger.)
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
