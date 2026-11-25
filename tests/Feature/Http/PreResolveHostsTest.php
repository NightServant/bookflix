<?php

use App\Support\PreResolveHosts;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Runs the middleware once and returns the options it handed on, or the exception it rejected with.
 *
 * @param  array<string, mixed>  $options
 * @return array<string, mixed>|Throwable
 */
function preResolve(string $url, array $options = []): array|Throwable
{
    $seen = [];
    $handler = (new PreResolveHosts)(function ($request, array $options) use (&$seen) {
        $seen = $options;

        return Create::promiseFor(new Response);
    });

    try {
        $handler(new Request('GET', $url), $options)->wait();
    } catch (Throwable $e) {
        return $e;
    }

    return $seen;
}

test('curl gets the address, so it skips its own lookup', function () {
    expect(preResolve('http://localhost:8001/embed')['curl'][CURLOPT_RESOLVE])->toBe(['localhost:8001:127.0.0.1'])
        ->and(preResolve('https://localhost/v1')['curl'][CURLOPT_RESOLVE])->toBe(['localhost:443:127.0.0.1'])
        ->and(preResolve('http://127.0.0.1:8001')['curl'][CURLOPT_RESOLVE])->toBe(['127.0.0.1:8001:127.0.0.1']);
});

test('entries already set are kept', function () {
    $options = preResolve('http://localhost:8001', ['curl' => [CURLOPT_RESOLVE => ['example.test:80:10.0.0.1']]]);

    expect($options['curl'][CURLOPT_RESOLVE])->toBe(['example.test:80:10.0.0.1', 'localhost:8001:127.0.0.1']);
});

test('a caller\'s pin for the same host and port wins', function () {
    $pin = ['localhost:8001:10.0.0.9'];

    expect(preResolve('http://localhost:8001', ['curl' => [CURLOPT_RESOLVE => $pin]])['curl'][CURLOPT_RESOLVE])->toBe($pin)
        // Pinned, so no lookup: a name DNS does not know still goes through.
        ->and(preResolve('https://pinned.invalid/', ['curl' => [CURLOPT_RESOLVE => ['pinned.invalid:443:10.0.0.9']]]))->toBeArray();
});

test('a host that does not resolve fails as a connection error, like curl', function () {
    expect(preResolve('https://no-such-host.invalid/'))->toBeInstanceOf(ConnectException::class);
});
