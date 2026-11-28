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
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        ]);

        // Two years (63072000 s), includeSubDomains and preload (the owner's decision; submitting the domain at hstspreload.org is their step). Only over https, and never
        // in the local environment: a browser ignores it on plain http, and a two-year includeSubDomains pin would stick to a local secured site (the Herd .test host).
        // Behind a TLS-terminating proxy `isSecure()` is true only when that proxy is trusted (TRUSTED_PROXIES, config/trustedproxy.php).
        if ($request->isSecure() && ! app()->isLocal()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
        }

        return $response;
    }
}
