<?php

use App\Jobs\RunMlJob;
use App\Models\MlJobRun;
use App\Models\ModelVersion;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config(['services.ml.url' => 'http://ml.test', 'services.ml.token' => str_repeat('s', 32)]);
    Http::fake(['*/health' => fn () => Http::response(['status' => 'ok'])]);
});

test('the models page lists versions, runs and the service health, and shows a failure', function () {
    ModelVersion::factory()->active()->create(['name' => 'lsa-test', 'version' => 'v7', 'metrics' => ['ndcg@10' => 0.1234, 'coverage' => 0.5]]);
    MlJobRun::factory()->create(['name' => 'train-lsa', 'status' => 'failed', 'error' => 'boom']);

    $this->actingAs(User::factory()->admin()->create())->get(route('admin.models'))
        ->assertOk()->assertSee('lsa-test')->assertSee('v7')->assertSee('0.123')->assertSee('boom')->assertSee('train-lsa')->assertSee('ML service: healthy');
});

test('the newest version comes first', function () {
    ModelVersion::factory()->create(['name' => 'older', 'created_at' => now()->subDay()]);
    ModelVersion::factory()->create(['name' => 'newer', 'created_at' => now()]);
    $this->actingAs(User::factory()->admin()->create());

    // The weight sets the migrations seed are in the table too.
    expect(Livewire::test('pages::admin.models.index')->instance()->versions->pluck('name')->intersect(['newer', 'older'])->values()->all())->toBe(['newer', 'older']);
});

test('the page copes with an unreachable ml service', function () {
    Http::swap(new Factory);
    Http::fake(['*' => Http::response(null, 503)]);

    $this->actingAs(User::factory()->admin()->create())->get(route('admin.models'))->assertOk()->assertSee('ML service: unavailable');
});

test('the models page is forbidden for non admins', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.models'))->assertForbidden();
});

test('run now queues the pipeline from its first step, for an admin only', function () {
    Bus::fake([RunMlJob::class]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.models.index')->call('runNow', 'item-cf');
    Bus::assertDispatched(RunMlJob::class, fn (RunMlJob $job) => $job->name === 'train-item-cf' && count($job->then) === 2);

    Livewire::test('pages::admin.models.index')->call('runNow', 'nonsense');
    Bus::assertDispatchedTimes(RunMlJob::class, 1);
});

test('every pipeline on the page exists, and every pipeline is on the page', function () {
    $this->actingAs(User::factory()->admin()->create());

    expect(array_keys(Livewire::test('pages::admin.models.index')->instance()->pipelines))->toEqualCanonicalizing(array_keys(RunMlJob::PIPELINES));
});

test('run now queues the embedding pipeline', function () {
    Bus::fake([RunMlJob::class]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.models.index')->assertSee('Run Embeddings and summaries')->call('runNow', 'embed');

    Bus::assertDispatched(RunMlJob::class, fn (RunMlJob $job) => $job->name === 'summarize-media' && $job->then === [['embed-media', []]] && $job->pipeline === 'embed');
});

test('run now queues the embedding similarities pipeline', function () {
    Bus::fake([RunMlJob::class]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.models.index')->assertSee('Run Semantic similarity')->call('runNow', 'embedding-similarities');

    Bus::assertDispatched(RunMlJob::class, fn (RunMlJob $job) => $job->name === 'build-similarities' && $job->params === ['methods' => ['embedding']] && $job->pipeline === 'embedding-similarities');
});

test('run now is refused with a message while that pipeline is running, and its button shows it', function () {
    Bus::fake([RunMlJob::class]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.models.index')->call('runNow', 'lsa');
    Bus::assertDispatchedTimes(RunMlJob::class, 1);

    Livewire::test('pages::admin.models.index')
        ->assertSeeHtml('data-test="run-now-lsa"')->assertSee('Text similarity: running')
        ->call('runNow', 'lsa')->assertDispatched('toast-show', fn (string $event, array $params) => str_contains(json_encode($params), 'already running'));
    Bus::assertDispatchedTimes(RunMlJob::class, 1);

    expect(Livewire::test('pages::admin.models.index')->instance()->running)->toBe(['item-cf' => false, 'lsa' => true, 'popularity' => false, 'embed' => false, 'embedding-similarities' => false]);
});

test('run now is refused once the reader is no longer an admin', function () {
    Bus::fake([RunMlJob::class]);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $page = Livewire::test('pages::admin.models.index');

    $admin->forceFill(['is_admin' => false])->save();
    $page->call('runNow', 'lsa')->assertForbidden();

    Bus::assertNotDispatched(RunMlJob::class);
});

test('the admin index links to the models page', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.index'))->assertSee(route('admin.models'), false);
});
