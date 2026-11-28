<?php

/*
|--------------------------------------------------------------------------
| Trusted Proxies
|--------------------------------------------------------------------------
|
| The proxies whose forwarded headers (X-Forwarded-For, -Host, -Port, -Proto) the app believes: the client's address and whether the request was https.
| Laravel's TrustProxies middleware falls back to this value when no code calls `trustProxies(at: ...)`.
|
| `TRUSTED_PROXIES` unset or empty trusts none (the default: a client that reaches the app directly cannot fake its address or scheme).
| `*` trusts every caller: set it only where the app can be reached solely through a proxy that overwrites those headers, as on Vercel.
| Otherwise a comma-separated list of proxy addresses or CIDR ranges, e.g. `10.0.0.1,192.0.2.0/24`.
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
