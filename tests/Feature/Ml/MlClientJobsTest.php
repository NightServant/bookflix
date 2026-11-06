<?php

use App\Ml\MlClient;
use App\Ml\MlServiceUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['services.ml.url' => 'http://ml.test', 'services.ml.token' => str_repeat('s', 32)]));

test('start job posts params and returns the run id', function () {
    Http::fake(['ml.test/v1/jobs/train-lsa' => Http::response(contract('jobs-start.json'), 202)]);

    expect(app(MlClient::class)->startJob('train-lsa', ['components' => 200]))->toBe(contract('jobs-start.json')['run_id']);
    Http::assertSent(fn (Request $request) => (array) $request['params'] === ['components' => 200] && $request->hasHeader('Authorization'));
});

test('start job sends an empty params object, not a list', function () {
    Http::fake(['ml.test/v1/jobs/*' => Http::response(contract('jobs-start.json'), 202)]);

    app(MlClient::class)->startJob('train-lsa');
    Http::assertSent(fn (Request $request) => $request->body() === '{"params":{}}');
});

test('start job throws unless the service accepts it with a run id', function (int $status, mixed $body) {
    Http::fake(['ml.test/v1/jobs/*' => Http::response($body, $status)]);

    expect(fn () => app(MlClient::class)->startJob('train-lsa'))->toThrow(MlServiceUnavailable::class);
})->with([
    'server error' => [500, null],
    'unknown job' => [404, ['detail' => 'Unknown job']],
    'ok without 202' => [200, ['run_id' => 'x']],
    'no run id' => [202, []],
]);

test('job status maps the contract and non 2xx throws', function () {
    Http::fake(['ml.test/v1/jobs/abc' => Http::response(contract('job-failed.json'))]);

    $job = app(MlClient::class)->job('abc');
    expect($job['status'])->toBe('failed')->and($job['error'])->toBe('Not enough media to train.')->and($job['metrics'])->toBeNull();

    Http::fake(['ml.test/v1/jobs/ok' => Http::response(contract('job-succeeded.json'))]);
    expect(app(MlClient::class)->job('ok'))->toMatchArray(['status' => 'succeeded', 'metrics' => ['items' => 1200, 'explained_variance' => 0.41, 'version_id' => 42]]);

    Http::fake(['ml.test/v1/jobs/broken' => Http::response(null, 500)]);
    expect(fn () => app(MlClient::class)->job('broken'))->toThrow(MlServiceUnavailable::class);
});

test('a job run id is encoded into the path', function () {
    Http::fake(['ml.test/*' => Http::response(contract('job-succeeded.json'))]);

    app(MlClient::class)->job('a/b c');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v1/jobs/a%2Fb%20c'));
});
