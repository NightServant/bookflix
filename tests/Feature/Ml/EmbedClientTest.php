<?php

use App\Ml\EmbeddingResult;
use App\Ml\MlClient;
use App\Ml\MlServiceUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['services.ml.url' => 'http://ml.test', 'services.ml.token' => str_repeat('s', 32), 'services.ml.timeout' => 5.0, 'services.ml.connect_timeout' => 1.0]));

test('embed posts the texts and kind and maps the contract', function () {
    Http::fake(['ml.test/v1/embed' => Http::response(contract('embed.json'))]);

    $result = app(MlClient::class)->embed(['dark fantasy manga'], 'query');

    expect($result)->toBeInstanceOf(EmbeddingResult::class)
        ->and($result->modelVersion)->toBe(contract('embed.json')['model_version'])
        ->and($result->dims)->toBe(384)
        ->and($result->vectors)->toHaveCount(1)
        ->and($result->vectors[0])->toHaveCount(384)
        ->and($result->vectors[0][0])->toBeFloat();
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['texts'] === ['dark fantasy manga'] && $request['kind'] === 'query'
        && $request->hasHeader('Authorization', 'Bearer '.str_repeat('s', 32)));
});

test('embed applies a float timeout and a short connect timeout', function (?float $timeout, float $expected, float $connect) {
    $options = [];
    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(contract('embed.json'));
    });

    app(MlClient::class)->embed(['x'], 'query', $timeout);

    expect($options['timeout'])->toBe($expected)->and($options['connect_timeout'])->toBe($connect);
})->with([
    'search' => [0.3, 0.3, 0.3],
    'default' => [null, 5.0, 1.0],
]);

test('the configured timeout keeps a fraction of a second', function () {
    $options = [];
    config(['services.ml.timeout' => 2.5]);
    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(contract('models.json'));
    });

    app(MlClient::class)->models();

    expect($options['timeout'])->toBe(2.5);
});

test('embed throws unavailable when the service fails or answers out of shape', function (mixed $body, int $status) {
    Http::fake(['ml.test/v1/embed' => Http::response($body, $status)]);

    expect(fn () => app(MlClient::class)->embed(['x'], 'query'))->toThrow(MlServiceUnavailable::class);
})->with([
    'server error' => [null, 500],
    'model not loaded' => [['detail' => 'The embedding model is not loaded.'], 503],
    'validation error' => [['detail' => []], 422],
    'not json' => ['oops', 200],
    'no vectors' => [['model_version' => 'm@r', 'dims' => 384], 200],
    'no model version' => [['dims' => 384, 'vectors' => [array_fill(0, 384, 0.1)]], 200],
    'dims not an int' => [['model_version' => 'm@r', 'dims' => '384', 'vectors' => [array_fill(0, 384, 0.1)]], 200],
    'a vector of other length' => [['model_version' => 'm@r', 'dims' => 384, 'vectors' => [array_fill(0, 200, 0.1)]], 200],
    'a vector with a string' => [['model_version' => 'm@r', 'dims' => 2, 'vectors' => [[0.1, 'x']]], 200],
    'fewer vectors than texts' => [['model_version' => 'm@r', 'dims' => 384, 'vectors' => []], 200],
]);

test('embed throws unavailable when the service cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fn () => app(MlClient::class)->embed(['x'], 'query', 0.3))->toThrow(MlServiceUnavailable::class);
});

test('embed with an empty token never calls the service', function () {
    config(['services.ml.token' => '']);
    Http::fake();

    expect(fn () => app(MlClient::class)->embed(['x'], 'query'))->toThrow(MlServiceUnavailable::class);
    Http::assertNothingSent();
});

test('models carries the model revision', function () {
    Http::fake(['ml.test/v1/models' => Http::response(contract('models.json'))]);

    expect(app(MlClient::class)->models()['embedding'])->toBe(contract('models.json')['embedding']);
});
