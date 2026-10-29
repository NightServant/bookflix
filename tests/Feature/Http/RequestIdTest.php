<?php

use App\Ml\MlClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

test('responses carry a generated request id', function () {
    expect(Str::isUuid($this->get(route('landing'))->headers->get('X-Request-Id')))->toBeTrue();
});

test('a valid incoming request id is reused', function () {
    $id = (string) Str::uuid();

    $this->withHeader('X-Request-Id', $id)->get(route('landing'))->assertHeader('X-Request-Id', $id);
});

test('an invalid incoming request id is replaced', function () {
    $header = $this->withHeader('X-Request-Id', '<script>')->get(route('landing'))->headers->get('X-Request-Id');

    expect($header)->not->toBe('<script>')->and(Str::isUuid($header))->toBeTrue();
});

test('the request id is shared through context', function () {
    Route::middleware('web')->get('/_test/context', fn () => Context::get('request_id'));

    $response = $this->get('/_test/context');

    expect($response->getContent())->toBe($response->headers->get('X-Request-Id'));
});

test('the ml client forwards the request id', function () {
    config(['services.ml.url' => 'http://ml.test', 'services.ml.token' => 'secret']);
    Http::fake(['ml.test/health' => Http::response(['status' => 'ok'])]);
    Context::add('request_id', 'abc-123');

    app(MlClient::class)->health();

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Request-Id', 'abc-123'));
});
