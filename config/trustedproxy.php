<?php

/*
|--------------------------------------------------------------------------
| Trusted Proxies
|--------------------------------------------------------------------------
|
| The proxies whose forwarded headers (X-Forwarded-For, -Port, -Proto) the app believes: the client's address and whether the request was https.
| X-Forwarded-Host is deliberately NOT believed (see bootstrap/app.php): the reset and verification mails build their links from the request's host, so a
| client-supplied one would put an attacker's address in a real email carrying the victim's token.
| Laravel's TrustProxies middleware falls back to this value when no code calls `trustProxies(at: ...)`.
|
| `TRUSTED_PROXIES` unset or empty trusts none (the default: a client that reaches the app directly cannot fake its address or scheme).
| `*` trusts every caller: set it only where the app can be reached solely through a proxy that overwrites those headers, as on Vercel. With `*` the client
| address is the LEFTMOST X-Forwarded-For entry: whatever the client sent, unless the proxy replaces the header (Vercel does).
| Otherwise a comma-separated list of proxy addresses or CIDR ranges, e.g. `10.0.0.1,192.0.2.0/24`; then the client address is the rightmost entry that is not
| one of the listed proxies, which a client cannot forge.
| A CDN in front of the host (for example Cloudflare's proxied mode in front of Vercel) makes every visitor appear as the CDN's address, so all of them share
| the per-IP rate limits; trust and read the CDN's own header instead if that matters.
|
*/

$proxies = env('TRUSTED_PROXIES');
$proxies = is_string($proxies) ? trim($proxies) : null;

return [
    'proxies' => match (true) {
        $proxies === null || $proxies === '' => null,
        $proxies === '*' || $proxies === '**' => $proxies,
        default => array_values(array_filter(array_map('trim', explode(',', $proxies)), fn (string $proxy): bool => $proxy !== '')),
    },
];
