<?php

use App\Ml\MlClient;
use App\Ml\MlServiceUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['services.ml.url' => 'http://ml.test', 'services.ml.token' => str_repeat('s', 32), 'services.ml.timeout' => 5.0, 'services.ml.connect_timeout' => 1.0]));

test('summarize posts the text and sentence count with the token and returns the summary', function () {
    Http::fake(['ml.test/v1/summarize' => Http::response(['summary' => 'A fine read.', 'method' => 'lsa'])]);

    expect(app(MlClient::class)->summarize("One.\n\nTwo.", 2))->toBe('A fine read.');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['text'] === "One.\n\nTwo." && $request['sentences'] === 2
        && $request->hasHeader('Authorization', 'Bearer '.str_repeat('s', 32)));
});

test('summarize asks for three sentences by default', function () {
    Http::fake(['ml.test/v1/summarize' => Http::response(['summary' => 'x', 'method' => 'lsa'])]);

    app(MlClient::class)->summarize('Text.');

    Http::assertSent(fn (Request $request) => $request['sentences'] === 3);
});

test('summarize returns null when the service finds no good summary', function () {
    Http::fake(['ml.test/v1/summarize' => Http::response(['summary' => null, 'method' => 'lsa'])]);

    expect(app(MlClient::class)->summarize('Short.'))->toBeNull();
});

test('summarize throws when the service is down, rejects the request or answers out of shape', function (Closure $fake) {
    Http::fake(['ml.test/v1/summarize' => $fake]);

    app(MlClient::class)->summarize('Text.');
})->throws(MlServiceUnavailable::class)->with([
    'connection' => [fn () => throw new ConnectionException('down')],
    '503' => [fn () => Http::response([], 503)],
    '422' => [fn () => Http::response(['detail' => []], 422)],
    'not a string' => [fn () => Http::response(['summary' => ['a'], 'method' => 'lsa'])],
    'blank' => [fn () => Http::response(['summary' => '  ', 'method' => 'lsa'])],
    'no summary key' => [fn () => Http::response(['method' => 'lsa'])],
    'an empty object' => [fn () => Http::response([])],
    'a page, not JSON' => [fn () => Http::response('<html>Bad gateway</html>', 200)],
    'a JSON string' => [fn () => Http::response('"ok"', 200, ['Content-Type' => 'application/json'])],
]);

test('summarize without a token throws before any request', function () {
    config(['services.ml.token' => '']);
    Http::fake();

    expect(fn () => app(MlClient::class)->summarize('Text.'))->toThrow(MlServiceUnavailable::class);
    Http::assertNothingSent();
});
