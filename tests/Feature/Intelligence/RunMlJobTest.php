<?php

use App\Jobs\RunMlJob;
use App\Ml\MlClient;
use App\Ml\MlServiceUnavailable;
use App\Models\MlJobRun;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(fn () => config(['services.ml.url' => 'http://ml.test', 'services.ml.token' => str_repeat('s', 32)]));

/**
 * The ML service accepting a job as `r1` and answering its status with each of `$statuses` in turn.
 *
 * @param  list<string>  $statuses
 */
function mlFake(array $statuses, ?string $error = null, array $metrics = ['items' => 3]): void
{
    $answers = array_map(fn (string $status) => Http::response([
        'status' => $status, 'started_at' => null, 'finished_at' => null,
        'metrics' => $status === 'succeeded' ? $metrics : null, 'error' => $status === 'failed' ? $error : null,
    ]), $statuses);

    Http::fake([
        'ml.test/v1/jobs/r1' => Http::sequence($answers)->whenEmpty(Http::response(['status' => 'running', 'started_at' => null, 'finished_at' => null, 'metrics' => null, 'error' => null])),
        'ml.test/v1/jobs/*' => Http::response(['run_id' => 'r1'], 202),
    ]);
}

/** Starts a job and polls it once, the way the queue would. */
function runOnce(RunMlJob $job): void
{
    $job->handle(app(MlClient::class));
}

test('every pipeline runs train, build-similarities and evaluate in that order, and the popularity refresh alone', function () {
    expect(RunMlJob::PIPELINES['item-cf'])->toBe([['train-item-cf', []], ['build-similarities', ['methods' => ['item_cf']]], ['evaluate', ['kind' => 'item_cf']]])
        ->and(RunMlJob::PIPELINES['lsa'])->toBe([['train-lsa', []], ['build-similarities', ['methods' => ['lsa']]], ['evaluate', ['kind' => 'lsa']]])
        ->and(RunMlJob::PIPELINES['popularity'])->toBe([['refresh-popularity', []]]);
});

test('starting a job records the run and polls it again in thirty seconds', function () {
    Bus::fake([RunMlJob::class]);
    mlFake(['running']);

    runOnce(RunMlJob::pipeline('lsa'));

    $run = MlJobRun::sole();
    expect($run->name)->toBe('train-lsa')->and($run->run_id)->toBe('r1')->and($run->status)->toBe('queued');
    Bus::assertDispatched(RunMlJob::class, fn (RunMlJob $job) => $job->mlJobRunId === $run->id && $job->delay === 30 && $job->queue === 'default');
});

test('a successful step stores its metrics and chains the next step', function () {
    Bus::fake([RunMlJob::class]);
    mlFake(['succeeded'], metrics: ['items' => 3, 'version_id' => 7]);
    $run = MlJobRun::factory()->create(['name' => 'train-item-cf', 'run_id' => 'r1', 'status' => 'running']);

    runOnce(new RunMlJob('train-item-cf', [], [['build-similarities', ['methods' => ['item_cf']]], ['evaluate', ['kind' => 'item_cf']]], $run->id));

    expect($run->refresh())->status->toBe('succeeded')->metrics->toBe(['items' => 3, 'version_id' => 7])->finished_at->not->toBeNull();
    Bus::assertDispatched(RunMlJob::class, fn (RunMlJob $job) => $job->name === 'build-similarities' && $job->params === ['methods' => ['item_cf'], 'version_id' => 7]
        && $job->then === [['evaluate', ['kind' => 'item_cf', 'version_id' => 7]]] && $job->mlJobRunId === null);
});

test('the last step ends the chain', function () {
    Bus::fake([RunMlJob::class]);
    mlFake(['succeeded']);
    $run = MlJobRun::factory()->create(['name' => 'evaluate', 'run_id' => 'r1', 'status' => 'running']);

    runOnce(new RunMlJob('evaluate', ['kind' => 'lsa'], [], $run->id));

    expect($run->refresh()->status)->toBe('succeeded');
    Bus::assertNotDispatched(RunMlJob::class);
});

test('a failed step stores the error and stops the chain', function () {
    Bus::fake([RunMlJob::class]);
    mlFake(['failed'], error: 'boom');
    $run = MlJobRun::factory()->create(['name' => 'train-lsa', 'run_id' => 'r1', 'status' => 'running']);

    runOnce(new RunMlJob('train-lsa', [], [['build-similarities', []]], $run->id));

    expect($run->refresh())->status->toBe('failed')->error->toBe('boom')->finished_at->not->toBeNull();
    Bus::assertNotDispatched(RunMlJob::class);
});

test('a run still going is polled again and shows as running', function () {
    Bus::fake([RunMlJob::class]);
    mlFake(['running']);
    $run = MlJobRun::factory()->create(['run_id' => 'r1']);

    runOnce(new RunMlJob('train-lsa', [], [], $run->id));

    expect($run->refresh()->status)->toBe('running');
    Bus::assertDispatched(RunMlJob::class, fn (RunMlJob $job) => $job->mlJobRunId === $run->id && $job->delay === 30);
});

test('a run that never finishes fails after two hours and does not chain', function () {
    Bus::fake([RunMlJob::class]);
    mlFake(['running']);
    $run = MlJobRun::factory()->create(['run_id' => 'r1', 'status' => 'running', 'started_at' => now()->subHours(2)->subMinute()]);

    runOnce(new RunMlJob('train-lsa', [], [['build-similarities', []]], $run->id));

    expect($run->refresh())->status->toBe('failed')->error->toContain('two hours');
    Bus::assertNotDispatched(RunMlJob::class);
});

test('an unreachable service while polling is retried until the deadline', function () {
    Bus::fake([RunMlJob::class]);
    Http::fake(['*' => Http::response(null, 503)]);
    $run = MlJobRun::factory()->create(['run_id' => 'r1', 'status' => 'running']);

    runOnce(new RunMlJob('train-lsa', [], [], $run->id));

    expect($run->refresh()->status)->toBe('running');
    Bus::assertDispatched(RunMlJob::class, fn (RunMlJob $job) => $job->mlJobRunId === $run->id);
});

test('a service that cannot start the job releases it, and when it gives up the run is failed with the reason', function () {
    Http::fake(['*' => Http::response(null, 503)]);
    $job = (new RunMlJob('train-lsa'))->withFakeQueueInteractions();

    $job->handle(app(MlClient::class));

    $job->assertReleased();
    expect(MlJobRun::count())->toBe(0);

    $job->failed(new MlServiceUnavailable('The ML service did not start the job.'));

    expect(MlJobRun::sole())->name->toBe('train-lsa')->status->toBe('failed')->error->toBe('The ML service did not start the job.');
});

test('a crash while polling fails the run that was open', function () {
    $run = MlJobRun::factory()->create(['run_id' => 'r1', 'status' => 'running']);

    (new RunMlJob('train-lsa', [], [], $run->id))->failed(new RuntimeException('worker died'));

    expect($run->refresh())->status->toBe('failed')->error->toBe('worker died')
        ->and(MlJobRun::count())->toBe(1);
});

test('a train step passes its version id to the steps after it', function () {
    Bus::fake([RunMlJob::class]);
    mlFake(['succeeded'], metrics: ['items' => 3, 'version_id' => 42]);
    $run = MlJobRun::factory()->create(['name' => 'train-lsa', 'run_id' => 'r1', 'status' => 'running']);

    runOnce(new RunMlJob('train-lsa', [], [['build-similarities', ['methods' => ['lsa']]], ['evaluate', ['kind' => 'lsa']]], $run->id));

    Bus::assertDispatched(RunMlJob::class, fn (RunMlJob $job) => $job->name === 'build-similarities' && $job->params === ['methods' => ['lsa'], 'version_id' => 42]
        && $job->then === [['evaluate', ['kind' => 'lsa', 'version_id' => 42]]]);
});

test('a train step that succeeds without a version id ends the chain with a failed row that says why', function () {
    Bus::fake([RunMlJob::class]);
    mlFake(['succeeded'], metrics: ['items' => 3]);
    $run = MlJobRun::factory()->create(['name' => 'train-item-cf', 'run_id' => 'r1', 'status' => 'running']);

    runOnce(new RunMlJob('train-item-cf', [], [['build-similarities', ['methods' => ['item_cf']]], ['evaluate', ['kind' => 'item_cf']]], $run->id, pipeline: 'item-cf'));

    Bus::assertNotDispatched(RunMlJob::class);
    expect($run->refresh()->status)->toBe('succeeded')
        ->and(MlJobRun::where('status', 'failed')->sole())->name->toBe('build-similarities')->error->toContain('version_id');
});

test('only one run of a pipeline is accepted at a time, and the last step frees it', function () {
    Bus::fake([RunMlJob::class]);

    expect(RunMlJob::dispatchPipeline('lsa'))->toBeTrue()
        ->and(RunMlJob::isRunning('lsa'))->toBeTrue()
        ->and(RunMlJob::dispatchPipeline('lsa'))->toBeFalse()
        ->and(RunMlJob::dispatchPipeline('item-cf'))->toBeTrue();
    Bus::assertDispatchedTimes(RunMlJob::class, 2);

    mlFake(['succeeded']);
    $run = MlJobRun::factory()->create(['name' => 'evaluate', 'run_id' => 'r1', 'status' => 'running']);
    runOnce(new RunMlJob('evaluate', ['kind' => 'lsa'], [], $run->id, pipeline: 'lsa'));

    expect(RunMlJob::isRunning('lsa'))->toBeFalse()->and(RunMlJob::dispatchPipeline('lsa'))->toBeTrue();
});

test('a failed step frees its pipeline', function () {
    Bus::fake([RunMlJob::class]);
    RunMlJob::dispatchPipeline('lsa');
    mlFake(['failed'], error: 'boom');
    $run = MlJobRun::factory()->create(['name' => 'train-lsa', 'run_id' => 'r1', 'status' => 'running']);

    runOnce(new RunMlJob('train-lsa', [], [['build-similarities', []]], $run->id, pipeline: 'lsa'));

    expect(RunMlJob::isRunning('lsa'))->toBeFalse();
});

test('a job that gives up frees its pipeline', function () {
    Bus::fake([RunMlJob::class]);
    RunMlJob::dispatchPipeline('lsa');

    (new RunMlJob('train-lsa', pipeline: 'lsa'))->failed(new RuntimeException('down'));

    expect(RunMlJob::isRunning('lsa'))->toBeFalse();
});

test('a run the service no longer knows fails at once', function () {
    Bus::fake([RunMlJob::class]);
    Http::fake(['ml.test/v1/jobs/r1' => Http::response(['detail' => 'Unknown run'], 404)]);
    $run = MlJobRun::factory()->create(['run_id' => 'r1', 'status' => 'running']);

    runOnce(new RunMlJob('train-lsa', [], [['build-similarities', []]], $run->id));

    expect($run->refresh())->status->toBe('failed')->error->toContain('no longer knows');
    Bus::assertNotDispatched(RunMlJob::class);
});

test('a retry after the next poll could not be queued reuses the run and starts no second service job', function () {
    Http::fake(['ml.test/v1/jobs/*' => Http::response(['run_id' => 'r1'], 202)]);
    $job = new RunMlJob('train-lsa');
    $real = app(Dispatcher::class);
    $this->mock(Dispatcher::class)->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('redis down'));

    expect(fn () => $job->handle(app(MlClient::class)))->toThrow(RuntimeException::class);
    expect(MlJobRun::count())->toBe(1);

    app()->instance(Dispatcher::class, $real);
    Bus::fake([RunMlJob::class]);
    (unserialize(serialize($job)))->handle(app(MlClient::class));

    expect(MlJobRun::count())->toBe(1);
    Http::assertSentCount(1);
    Bus::assertDispatched(RunMlJob::class, fn (RunMlJob $next) => $next->mlJobRunId === MlJobRun::sole()->id);
});

test('a retry after the next step could not be queued queues it once, even when the run is already closed', function () {
    Cache::flush();
    mlFake(['succeeded'], metrics: ['items' => 3, 'version_id' => 7]);
    $run = MlJobRun::factory()->create(['name' => 'train-lsa', 'run_id' => 'r1', 'status' => 'running']);
    $job = new RunMlJob('train-lsa', [], [['build-similarities', ['methods' => ['lsa']]]], $run->id, 'lsa');
    $real = app(Dispatcher::class);
    $this->mock(Dispatcher::class)->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('redis down'));

    expect(fn () => runOnce($job))->toThrow(RuntimeException::class);
    expect($run->refresh()->status)->toBe('succeeded');

    app()->instance(Dispatcher::class, $real);
    Bus::fake([RunMlJob::class]);
    runOnce(unserialize(serialize($job)));
    runOnce(unserialize(serialize($job)));

    Bus::assertDispatchedTimes(RunMlJob::class, 1);
    Bus::assertDispatched(RunMlJob::class, fn (RunMlJob $next) => $next->name === 'build-similarities' && $next->params === ['methods' => ['lsa'], 'version_id' => 7]);
});

test('a retry that finds a failed run frees the pipeline', function () {
    Bus::fake([RunMlJob::class]);
    RunMlJob::dispatchPipeline('lsa');
    $run = MlJobRun::factory()->create(['name' => 'train-lsa', 'run_id' => 'r1', 'status' => 'failed']);

    runOnce(new RunMlJob('train-lsa', [], [['build-similarities', []]], $run->id, 'lsa'));

    expect(RunMlJob::isRunning('lsa'))->toBeFalse();
    Bus::assertNotDispatched(RunMlJob::class, fn (RunMlJob $job) => $job->name === 'build-similarities');
});

test('a start job that dies after its row was created closes that row instead of adding a second', function () {
    $run = MlJobRun::factory()->create(['name' => 'train-lsa', 'job_key' => $key = (string) Str::uuid(), 'run_id' => 'r1', 'status' => 'queued']);

    (new RunMlJob('train-lsa', jobKey: $key))->failed(new RuntimeException('redis down'));

    expect(MlJobRun::count())->toBe(1)
        ->and($run->refresh())->status->toBe('failed')->error->toBe('redis down')->finished_at->not->toBeNull();
});
