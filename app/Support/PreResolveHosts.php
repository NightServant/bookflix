<?php

namespace App\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * A Guzzle middleware that looks the host up with gethostbynamel() and gives curl the address (CURLOPT_RESOLVE), so curl never runs its own lookup.
 *
 * Only PHP-FPM on macOS loads it (AppServiceProvider). There, curl's getaddrinfo() in a forked FPM worker reaches Network.framework, which trips
 * Objective-C's fork-safety check and aborts the worker: any page that calls out (AI, the ML service, covers) answers 502 under Herd. On macOS
 * curl sends even IP literals through getaddrinfo() (for NAT64), so they are pinned too. gethostbynamel() takes a path that does not crash.
 * Linux, the CLI, queue workers and `php artisan serve` do not fork this way and never load it.
 *
 * ponytail: IPv4 only and no proxy support (curl would still look the proxy host up); a dev-machine shim, drop it once Herd fixes the crash.
 */
final class PreResolveHosts
{
    /**
     * @param  callable(RequestInterface, array<string, mixed>): PromiseInterface  $handler
     * @return callable(RequestInterface, array<string, mixed>): PromiseInterface
     */
    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $uri = $request->getUri();
            $host = $uri->getHost();

            // An IPv6 literal ("[::1]") has nothing to look up and gethostbynamel() cannot read it.
            if ($host === '' || str_contains($host, ':')) {
                return $handler($request, $options);
            }

            $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);

            $pinned = $options['curl'][CURLOPT_RESOLVE] ?? [];

            // A caller that already pinned this host and port wins (and needs no lookup).
            if (array_filter($pinned, fn (mixed $entry): bool => is_string($entry) && str_starts_with(ltrim($entry, '+'), "{$host}:{$port}:")) !== []) {
                return $handler($request, $options);
            }

            $addresses = gethostbynamel($host);

            if ($addresses === false || $addresses === []) {
                return Create::rejectionFor(new ConnectException("Could not resolve host: {$host}", $request));
            }

            $options['curl'][CURLOPT_RESOLVE] = [...$pinned, "{$host}:{$port}:".implode(',', array_unique($addresses))];

            return $handler($request, $options);
        };
    }
}
