<?php

use App\Ml\MlClient;
use App\Ml\MlServiceUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['services.ml.url' => 'http://ml.test', 'services.ml.token' => 'secret']));

test('models are read with the service token', function () {
    Http::fake(['ml.test/v1/models' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/ml-contracts/models.json')), true))]);

    expect(app(MlClient::class)->models()['embedding']['dims'])->toBe(384);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer secret'));
});

test('health is false when the service is down', function () {
    Http::fake(['ml.test/health' => Http::response(null, 503)]);

    expect(app(MlClient::class)->health())->toBeFalse();
});

test('models throws when the service is unavailable', function () {
    Http::fake(['ml.test/v1/models' => Http::response(null, 503)]);

    expect(fn () => app(MlClient::class)->models())->toThrow(MlServiceUnavailable::class);
});
