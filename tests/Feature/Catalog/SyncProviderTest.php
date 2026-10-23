<?php

use App\Catalog\Data\ProviderRecord;
use App\Catalog\Ingestion\IngestionPipeline;
use App\Catalog\Providers\AniList\AniListProvider;
use App\Catalog\Providers\MetadataProvider;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Enums\IngestionMode;
use App\Enums\IngestionStatus;
use App\Jobs\ImportMediaRecord;
use App\Jobs\SyncProvider;
use App\Models\ExternalSource;
use App\Models\IngestionRun;
use App\Models\Media;
use App\Models\MediaExternalId;
use Database\Seeders\MediaTypeSeeder;
use Illuminate\Bus\PendingBatch;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Testing\Fakes\BatchFake;

beforeEach(function () {
    app(MediaTypeSeeder::class)->run();
    ExternalSource::factory()->create(['key' => 'anilist']);
    RateLimiter::for('provider:anilist', fn () => Limit::none());
});

function providerRecord(): ProviderRecord
{
    return new ProviderRecord('anilist', '30002', providerFixture('anilist/manga-berserk.json'), null, now()->toImmutable());
}

function ingestionRun(): IngestionRun
{
    return IngestionRun::factory()->create([
        'external_source_id' => ExternalSource::firstWhere('key', 'anilist')->id,
        'status' => IngestionStatus::Running,
    ]);
}

function fakeProviderThrowing(Throwable $exception): void
{
    $provider = Mockery::mock(MetadataProvider::class);
    $provider->shouldReceive('key')->andReturn('anilist');
    $provider->shouldReceive('normalize')->andThrow($exception);
    app()->instance(AniListProvider::class, $provider);
}

/**
 * Serve an AniList page of the recorded media (30001 to 30003); `blankTitle` ids come back with a blank title.
 *
 * @param  list<int>  $blankTitle
 */
function fakeAniListPage(array $blankTitle = []): void
{
    $media = array_map(
        fn (array $media): array => in_array($media['id'], $blankTitle, true) ? [...$media, 'title' => ['romaji' => ' ', 'english' => null, 'native' => null]] : $media,
        providerFixture('anilist/page-1.json')['data']['Page']['media'],
    );

    Http::fake(['graphql.anilist.co' => Http::response(['data' => ['Page' => ['pageInfo' => ['hasNextPage' => false], 'media' => $media]]])]);
}

test('a provider 429 releases the job with the retry-after delay', function () {
    $run = ingestionRun();
    $job = new ImportMediaRecord(providerRecord(), 'anilist', $run->id);
    fakeProviderThrowing(new RateLimitedByProvider(retryAfter: 30));

    $fake = $job->withFakeQueueInteractions();
    $job->handle(app(IngestionPipeline::class));

    $fake->assertReleased(30);
    expect($run->fresh()->counts)->toMatchArray(['created' => 0, 'skipped' => 0, 'failed' => 0]);
});

test('a job backs off 10 60 300 seconds, is capped by exceptions and retried for two hours', function () {
    $job = new ImportMediaRecord(providerRecord(), 'anilist');
    $sync = new SyncProvider('anilist');

    expect($job->backoff())->toBe([10, 60, 300])->and($job->maxExceptions)->toBe(5)->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addHour()->getTimestamp())
        ->and($sync->backoff())->toBe([10, 60, 300])->and($sync->maxExceptions)->toBe(5)->and($sync->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addHour()->getTimestamp())
        ->and(property_exists($job, 'tries'))->toBeFalse();
});

test('a rate-limited release does not use up the job and never lands in failed jobs', function () {
    RateLimiter::for('provider:anilist', fn () => Limit::perMinute(1));
    ImportMediaRecord::dispatch(new ProviderRecord('anilist', '1', [], null, now()->toImmutable()), 'anilist')->onConnection('database');
    ImportMediaRecord::dispatch(providerRecord(), 'anilist')->onConnection('database');

    $worker = app('queue.worker');
    $options = new WorkerOptions(maxTries: 1);

    $worker->runNextJob('database', 'ingestion', $options);

    foreach (range(1, 3) as $ignored) {
        DB::table('jobs')->update(['available_at' => now()->getTimestamp()]);
        $worker->runNextJob('database', 'ingestion', $options);
    }

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('attempts'))->toBe(3)
        ->and(Media::count())->toBe(0);
});

test('a persistently failing provider marks the run failed and leaves existing media untouched', function () {
    $existing = Media::factory()->create(['title' => 'Keep me']);
    Http::fake(['graphql.anilist.co' => Http::response(null, 503)]);

    (new SyncProvider('anilist'))->handle();

    $run = IngestionRun::latest('id')->first();
    expect($run->status)->toBe(IngestionStatus::Failed)->and($run->error)->not->toBeNull()->and($run->finished_at)->not->toBeNull()
        ->and($existing->fresh()->title)->toBe('Keep me')
        ->and(Media::count())->toBe(1)
        ->and(DB::table('job_batches')->count())->toBe(0);
});

test('a provider 429 during the fetch fails the run and releases the sync with the retry-after delay', function () {
    Http::fake(['graphql.anilist.co' => Http::response(null, 429, ['Retry-After' => '45'])]);
    $job = new SyncProvider('anilist');

    $fake = $job->withFakeQueueInteractions();
    $job->handle();

    $fake->assertReleased(45);
    expect(IngestionRun::latest('id')->first()->status)->toBe(IngestionStatus::Failed);
});

test('a malformed record is skipped and counted while the rest succeed', function () {
    fakeAniListPage(blankTitle: [30002]);

    (new SyncProvider('anilist'))->handle();

    $run = IngestionRun::latest('id')->first();
    expect($run->status)->toBe(IngestionStatus::Succeeded)->and($run->error)->toBeNull()->and($run->finished_at)->not->toBeNull()
        ->and($run->counts)->toMatchArray(['fetched' => 3, 'created' => 2, 'skipped' => 1, 'failed' => 0])
        ->and(Media::count())->toBe(2)
        ->and(ExternalSource::firstWhere('key', 'anilist')->sync_cursor['since'])->toBe($run->started_at->toIso8601String());
});

test('a retried job never duplicates rows', function () {
    $job = new ImportMediaRecord(providerRecord(), 'anilist', ingestionRun()->id);
    $job->handle(app(IngestionPipeline::class));
    $job->handle(app(IngestionPipeline::class));

    expect(Media::count())->toBe(1)->and(MediaExternalId::where('external_id', '30002')->count())->toBe(1)
        ->and(IngestionRun::first()->counts)->toMatchArray(['created' => 1, 'skipped' => 1, 'failed' => 0]);
});

test('a job that gives up is counted as failed on its run', function () {
    $run = ingestionRun();

    (new ImportMediaRecord(providerRecord(), 'anilist', $run->id))->failed(new RuntimeException('boom'));
    (new ImportMediaRecord(providerRecord(), 'anilist', $run->id))->failed(new RuntimeException('boom'));

    expect($run->fresh()->counts['failed'])->toBe(2);
});

test('some records failing mid-run yields a partial run', function () {
    Bus::fake();
    fakeAniListPage();
    (new SyncProvider('anilist'))->handle();

    Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === 3);
    $finally = Bus::dispatchedBatches()[0]->finallyCallbacks()[0];
    $finally(new BatchFake('id', '', 3, 0, 1, ['x'], [], now()->toImmutable()));

    $run = IngestionRun::latest('id')->first();
    expect($run->status)->toBe(IngestionStatus::Partial)->and($run->error)->toContain('1 of 3')
        ->and(ExternalSource::firstWhere('key', 'anilist')->sync_cursor)->toBeNull();
});

test('every record failing yields a failed run', function () {
    SyncProvider::finalize(ingestionRun()->id, total: 3, failed: 3);

    expect(IngestionRun::first()->status)->toBe(IngestionStatus::Failed);
});

test('the sync cursor advances only after a successful run', function () {
    Http::fake(['graphql.anilist.co' => Http::response(null, 503)]);
    (new SyncProvider('anilist'))->handle();

    expect(ExternalSource::firstWhere('key', 'anilist')->sync_cursor)->toBeNull();
});

test('a full sync ignores the stored cursor', function () {
    ExternalSource::firstWhere('key', 'anilist')->update(['sync_cursor' => ['since' => '2026-01-01T00:00:00+00:00']]);
    fakeAniListPage();

    (new SyncProvider('anilist', IngestionMode::Full))->handle();

    Http::assertSent(fn ($request) => $request['variables']['sort'] === ['ID']);
    expect(IngestionRun::first()->mode)->toBe(IngestionMode::Full);
});

test('only enabled sources are scheduled', function () {
    Artisan::call('schedule:list');
    $isDue = fn () => collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'sync:anilist')->filtersPass(app());

    expect($isDue())->toBeTrue();

    ExternalSource::where('key', 'anilist')->update(['enabled' => false]);

    expect($isDue())->toBeFalse();
});

test('the sync command runs the fetch and rejects unknown providers', function () {
    fakeAniListPage();

    $this->artisan('bookflix:sync anilist --full')->expectsOutputToContain('3 record(s) fetched')->assertSuccessful();
    expect(IngestionRun::first()->mode)->toBe(IngestionMode::Full);

    $this->artisan('bookflix:sync nope')->expectsOutputToContain('Unknown provider')->assertFailed();
});
