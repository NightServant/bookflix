<?php

use App\Catalog\Data\ProviderRecord;
use App\Catalog\Ingestion\IngestionPipeline;
use App\Catalog\Providers\ProviderUnavailable;
use App\Enums\ImportOutcome;
use App\Enums\IngestionMode;
use App\Enums\IngestionStatus;
use App\Jobs\FetchProviderPage;
use App\Jobs\ScorePopularity;
use App\Jobs\SyncProvider;
use App\Models\ExternalSource;
use App\Models\IngestionRun;
use App\Models\Media;
use Carbon\CarbonImmutable;
use Database\Seeders\MediaTypeSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    app(MediaTypeSeeder::class)->run();
    ExternalSource::factory()->create(['key' => 'anilist']);
    RateLimiter::for('provider:anilist', fn () => Limit::none());
});

function anilistSource(): ExternalSource
{
    return ExternalSource::firstWhere('key', 'anilist');
}

function ingestionRun(array $attributes = []): IngestionRun
{
    return IngestionRun::factory()->create([
        'external_source_id' => anilistSource()->id,
        'status' => IngestionStatus::Running,
        ...$attributes,
    ]);
}

/**
 * The recorded AniList media (30001 to 30003). Another `$firstId` renumbers them and gives them unrelated titles and no staff,
 * so a second batch never dedups into the first. `$blankTitle` ids come back with a blank title.
 *
 * @param  list<int>  $blankTitle
 * @return list<array<string, mixed>>
 */
function anilistMedia(int $firstId = 30001, array $blankTitle = []): array
{
    return array_map(function (array $media, int $index) use ($firstId, $blankTitle): array {
        $media['id'] = $firstId + $index;

        if ($firstId !== 30001) {
            $media['title'] = ['english' => ['Zebra Quest', 'Orchid Harbor', 'Granite Sky'][$index], 'romaji' => null, 'native' => null];
            $media['staff'] = ['edges' => []];
        }

        return in_array($media['id'], $blankTitle, true) ? [...$media, 'title' => ['romaji' => ' ', 'english' => null, 'native' => null]] : $media;
    }, providerFixture('anilist/page-1.json')['data']['Page']['media'], range(0, 2));
}

/**
 * @param  list<array<string, mixed>>  $media
 * @return array<string, mixed>
 */
function anilistPage(array $media, bool $hasNext = false): array
{
    return ['data' => ['Page' => ['pageInfo' => ['hasNextPage' => $hasNext], 'media' => $media]]];
}

function fakeAniListPage(array $blankTitle = []): void
{
    Http::fake(['graphql.anilist.co' => Http::response(anilistPage(anilistMedia(blankTitle: $blankTitle)))]);
}

function newestUpdate(array $media): CarbonImmutable
{
    return CarbonImmutable::createFromTimestampUTC(max(array_column($media, 'updatedAt')));
}

test('a sync runs page by page, imports each page inline and moves the cursor to its start minus the overlap', function () {
    $first = anilistMedia();
    $second = anilistMedia(30004);
    Http::fake(['graphql.anilist.co' => Http::sequence()->push(anilistPage($first, hasNext: true))->push(anilistPage($second))]);

    (new SyncProvider('anilist'))->handle();

    $run = IngestionRun::sole();
    expect($run->status)->toBe(IngestionStatus::Succeeded)->and($run->error)->toBeNull()->and($run->finished_at)->not->toBeNull()
        ->and($run->pages)->toBe(2)->and($run->page_token)->toBeNull()
        ->and($run->counts)->toMatchArray(['fetched' => 6, 'created' => 6, 'skipped' => 0, 'failed' => 0])
        ->and(Media::count())->toBe(6)
        ->and($run->max_updated_at->equalTo(newestUpdate($second)))->toBeTrue()
        ->and(anilistSource()->sync_cursor)->toEqual(['since' => $run->started_at->subMinutes(5)->toIso8601String()])
        ->and(anilistSource()->last_synced_at)->not->toBeNull();
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request['variables']['page'] === 2);
});

test('a successful sync queues a popularity scoring pass, a failed one does not', function () {
    Bus::fake([ScorePopularity::class]);
    Http::fake(['graphql.anilist.co' => Http::response(anilistPage(anilistMedia()))]);

    (new SyncProvider('anilist'))->handle();
    Bus::assertDispatchedTimes(ScorePopularity::class, 1);

    Bus::fake([ScorePopularity::class]);
    Http::fake(['graphql.anilist.co' => Http::response(anilistPage(anilistMedia(30004, blankTitle: [30004, 30005, 30006])))]);

    (new SyncProvider('anilist', IngestionMode::Full))->handle();
    Bus::assertNotDispatched(ScorePopularity::class);
});

test('a sync stops at max_pages_per_run and the next run resumes at the stored page', function () {
    config(['bookflix.providers.anilist.max_pages_per_run' => 1]);
    $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00:00', 'UTC'));
    $first = anilistMedia();
    $second = anilistMedia(30004);
    Http::fake(['graphql.anilist.co' => Http::sequence()->push(anilistPage($first, hasNext: true))->push(anilistPage($second))]);

    (new SyncProvider('anilist'))->handle();

    $cut = IngestionRun::sole();
    expect($cut->pages)->toBe(1)->and($cut->page_token)->toBe('2')->and(Media::count())->toBe(3)
        ->and($cut->max_updated_at->equalTo(newestUpdate($first)))->toBeTrue()
        ->and(anilistSource()->sync_cursor)->toEqual(['since' => null, 'resume' => ['mode' => 'incremental', 'page_token' => '2', 'max_updated_at' => newestUpdate($first)->toIso8601String(), 'walk_started_at' => '2026-10-23T10:00:00+00:00']]);

    $this->travel(10)->minutes();
    (new SyncProvider('anilist'))->handle();

    expect(IngestionRun::count())->toBe(2)->and(Media::count())->toBe(6)
        ->and(IngestionRun::latest('id')->first()->walk_started_at->toIso8601String())->toBe('2026-10-23T10:00:00+00:00')
        ->and(anilistSource()->sync_cursor)->toEqual(['since' => '2026-10-23T09:55:00+00:00']);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request['variables']['page'] === 2);
});

test('a record updated between the run start and its end is fetched again by the next run', function () {
    $this->travelTo($start = CarbonImmutable::parse('2026-10-23 10:00:00', 'UTC'));
    $stale = [...anilistMedia()[0], 'updatedAt' => $start->subMinutes(10)->getTimestamp()];
    $late = [...anilistMedia(30004)[0], 'updatedAt' => $start->addMinutes(3)->getTimestamp()];
    // The first record was edited at minute 2, after the walk read it but before the walk read a record stamped minute 3:
    // a cursor that followed the newest update (minute 3) would skip it on the next run.
    $edited = [...$stale, 'description' => 'Edited', 'updatedAt' => $start->addMinutes(2)->getTimestamp()];
    $old = [...anilistMedia(30007)[0], 'updatedAt' => $start->subHour()->getTimestamp()];
    $reads = 0;
    Http::fake(['graphql.anilist.co' => function () use (&$reads, $stale, $late, $edited, $old, $start) {
        if (++$reads === 2) {
            $this->travelTo($start->addMinutes(4));
        }

        return Http::response(match ($reads) {
            1 => anilistPage([$stale], hasNext: true),
            2 => anilistPage([$late]),
            default => anilistPage([$late, $edited, $old]),
        });
    }]);

    (new SyncProvider('anilist'))->handle();

    expect(anilistSource()->sync_cursor)->toEqual(['since' => '2026-10-23T09:55:00+00:00'])
        ->and(IngestionRun::sole()->max_updated_at->getTimestamp())->toBe($late['updatedAt']);

    (new SyncProvider('anilist'))->handle();

    expect(IngestionRun::latest('id')->first()->counts)->toMatchArray(['fetched' => 2, 'updated' => 1, 'skipped' => 1, 'failed' => 0])
        ->and(Media::where('description', 'Edited')->count())->toBe(1);
});

test('a full sync ignores the stored cursor and resume point', function () {
    anilistSource()->update(['sync_cursor' => ['since' => '2026-01-01T00:00:00+00:00', 'resume' => ['mode' => 'incremental', 'page_token' => '9', 'max_updated_at' => null]]]);
    fakeAniListPage();

    (new SyncProvider('anilist', IngestionMode::Full))->handle();

    Http::assertSent(fn ($request) => $request['variables']['sort'] === ['ID'] && $request['variables']['page'] === 1);
    expect(IngestionRun::sole()->mode)->toBe(IngestionMode::Full);
});

test('a malformed record is skipped and counted while the rest succeed', function () {
    fakeAniListPage(blankTitle: [30002]);

    (new SyncProvider('anilist'))->handle();

    $run = IngestionRun::sole();
    expect($run->status)->toBe(IngestionStatus::Succeeded)->and($run->counts)->toMatchArray(['fetched' => 3, 'created' => 2, 'skipped' => 1, 'failed' => 0])
        ->and(Media::count())->toBe(2);
});

test('records that fail leave a partial run, keep their ids, and the cursor still advances', function () {
    fakeAniListPage();
    $this->mock(IngestionPipeline::class)->shouldReceive('import')->andReturnUsing(
        fn (ProviderRecord $record) => $record->externalId === '30002' ? throw new RuntimeException('boom') : ImportOutcome::Created,
    );

    (new SyncProvider('anilist'))->handle();

    $run = IngestionRun::sole();
    expect($run->status)->toBe(IngestionStatus::Partial)->and($run->error)->toContain('1 of 3')
        ->and($run->counts)->toMatchArray(['fetched' => 3, 'created' => 2, 'failed' => 1, 'failed_ids' => [['id' => '30002', 'error' => 'boom']]])
        ->and(anilistSource()->sync_cursor['since'])->toBe($run->started_at->subMinutes(5)->toIso8601String());
});

test('only the first 100 failed ids are kept', function () {
    $media = array_map(fn (int $id) => [...anilistMedia()[0], 'id' => $id], range(1, 105));
    Http::fake(['graphql.anilist.co' => Http::response(anilistPage($media))]);
    $this->mock(IngestionPipeline::class)->shouldReceive('import')->andThrow(new RuntimeException('boom'));

    (new SyncProvider('anilist'))->handle();

    $run = IngestionRun::sole();
    expect($run->counts['failed'])->toBe(105)->and($run->counts['failed_ids'])->toHaveCount(100)->and($run->counts['failed_ids'][0])->toEqual(['id' => '1', 'error' => 'boom'])
        ->and($run->status)->toBe(IngestionStatus::Failed);
});

test('a provider outage or timeout leaves one failed run, never a stuck one or a row per retry', function (Closure $fake) {
    $existing = Media::factory()->create(['title' => 'Keep me']);
    Http::fake($fake());

    (new SyncProvider('anilist'))->handle();

    $run = IngestionRun::sole();
    expect($run->status)->toBe(IngestionStatus::Failed)->and($run->error)->not->toBeNull()->and($run->finished_at)->not->toBeNull()
        ->and($existing->fresh()->title)->toBe('Keep me')->and(Media::count())->toBe(1)
        ->and(anilistSource()->sync_cursor)->toBeNull();
})->with([
    '503' => [fn () => ['graphql.anilist.co' => Http::response(null, 503)]],
    'timeout' => [fn () => ['graphql.anilist.co' => fn () => throw new ConnectionException('cURL error 28: timed out')]],
]);

test('an exception escaping a page job leaves the run running for the queue retry, which reuses it', function () {
    $run = ingestionRun();
    Http::fake(['graphql.anilist.co' => Http::sequence()->push(null, 503)->push(anilistPage(anilistMedia()))]);
    $job = new FetchProviderPage($run->id);

    expect(fn () => $job->handle(app(IngestionPipeline::class)))->toThrow(ProviderUnavailable::class);
    expect($run->fresh()->status)->toBe(IngestionStatus::Running);

    $job->handle(app(IngestionPipeline::class));

    expect(IngestionRun::count())->toBe(1)->and($run->fresh()->status)->toBe(IngestionStatus::Succeeded);
});

test('a provider 429 releases the page with the retry-after delay and keeps the same running run', function () {
    $run = ingestionRun();
    Http::fake(['graphql.anilist.co' => Http::sequence()->push(null, 429, ['Retry-After' => '45'])->push(anilistPage(anilistMedia()))]);
    $job = new FetchProviderPage($run->id);

    $fake = $job->withFakeQueueInteractions();
    $job->handle(app(IngestionPipeline::class));

    $fake->assertReleased(45);
    expect(IngestionRun::count())->toBe(1)->and($run->fresh()->status)->toBe(IngestionStatus::Running)->and($run->fresh()->pages)->toBe(0);

    $job->handle(app(IngestionPipeline::class));

    expect(IngestionRun::count())->toBe(1)->and($run->fresh()->status)->toBe(IngestionStatus::Succeeded)->and(Media::count())->toBe(3);
});

test('a page job that gives up closes its run as failed and a finished run is left alone', function () {
    $stuck = ingestionRun(['page_token' => '4', 'max_updated_at' => '2026-10-20 10:00:00']);
    $done = ingestionRun(['status' => IngestionStatus::Succeeded]);

    (new FetchProviderPage($stuck->id, '4'))->failed(new MaxAttemptsExceededException('has been attempted too many times'));
    (new FetchProviderPage($done->id))->failed(new RuntimeException('late'));

    expect($stuck->fresh()->status)->toBe(IngestionStatus::Failed)->and($stuck->fresh()->error)->toContain('attempted too many times')->and($stuck->fresh()->finished_at)->not->toBeNull()
        ->and($done->fresh()->status)->toBe(IngestionStatus::Succeeded)->and($done->fresh()->error)->toBeNull()
        ->and(anilistSource()->sync_cursor['resume'])->toEqual(['mode' => 'incremental', 'page_token' => '4', 'max_updated_at' => '2026-10-20T10:00:00+00:00', 'walk_started_at' => $stuck->started_at->toIso8601String()]);
});

test('the next sync continues a failed run from the page it stopped at', function () {
    ingestionRun(['page_token' => '3'])->fail('worker died');
    Http::fake(['graphql.anilist.co' => Http::response(anilistPage(anilistMedia()))]);

    (new SyncProvider('anilist'))->handle();

    expect(IngestionRun::count())->toBe(2)->and(IngestionRun::latest('id')->first()->status)->toBe(IngestionStatus::Succeeded);
    Http::assertSent(fn ($request) => $request['variables']['page'] === 3);
});

test('a sync job that gives up closes the run it opened before any page, not a live one', function () {
    $orphan = ingestionRun(['pages' => 0]);
    $live = ingestionRun(['pages' => 2]);

    (new SyncProvider('anilist'))->failed(new RuntimeException('killed'));

    expect($orphan->fresh()->status)->toBe(IngestionStatus::Failed)->and($orphan->fresh()->error)->toBe('killed')->and($live->fresh()->status)->toBe(IngestionStatus::Running);
});

test('a sync leaves a run that is still in flight alone and reaps one that stopped making progress', function () {
    $running = ingestionRun();
    Http::fake();

    (new SyncProvider('anilist'))->handle();

    expect(IngestionRun::count())->toBe(1)->and($running->fresh()->status)->toBe(IngestionStatus::Running);
    Http::assertNothingSent();

    $this->travel(4)->hours();
    fakeAniListPage();
    (new SyncProvider('anilist'))->handle();

    expect($running->fresh()->status)->toBe(IngestionStatus::Failed)->and(IngestionRun::count())->toBe(2)
        ->and(IngestionRun::latest('id')->first()->status)->toBe(IngestionStatus::Succeeded);
});

test('a page job is rate limited per provider, backs off 10 60 300 seconds, is capped by exceptions and retried for two hours', function () {
    $job = new FetchProviderPage(ingestionRun()->id);
    $sync = new SyncProvider('anilist');

    expect($job->queue)->toBe('ingestion')->and($job->middleware())->toHaveCount(1)->and($job->middleware()[0])->toBeInstanceOf(RateLimited::class)
        ->and($job->backoff())->toBe([10, 60, 300])->and($job->maxExceptions)->toBe(5)->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addHour()->getTimestamp())
        ->and($sync->backoff())->toBe([10, 60, 300])->and($sync->maxExceptions)->toBe(5)->and($sync->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addHour()->getTimestamp())
        ->and(property_exists($job, 'tries'))->toBeFalse()
        ->and(RateLimiter::limiter('provider:anilist'))->not->toBeNull();
});

test('a rate-limited release does not use up the page job and never lands in failed jobs', function () {
    RateLimiter::for('provider:anilist', fn () => Limit::perMinute(1));
    fakeAniListPage();
    $run = ingestionRun();
    FetchProviderPage::dispatch($run->id)->onConnection('database');
    FetchProviderPage::dispatch($run->id)->onConnection('database');

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
        ->and(Media::count())->toBe(3);
});

test('only enabled sources are scheduled', function () {
    Artisan::call('schedule:list');
    $isDue = fn () => collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'sync:anilist')->filtersPass(app());

    expect($isDue())->toBeTrue();

    ExternalSource::where('key', 'anilist')->update(['enabled' => false]);

    expect($isDue())->toBeFalse();
});

test('the sync command opens a run and rejects unknown providers', function () {
    fakeAniListPage();

    $this->artisan('bookflix:sync anilist --full')->expectsOutputToContain('3 record(s) fetched')->assertSuccessful();
    expect(IngestionRun::sole()->mode)->toBe(IngestionMode::Full);

    $this->artisan('bookflix:sync nope')->expectsOutputToContain('Unknown provider')->assertFailed();
});

test('the sync command refuses a disabled source unless forced', function () {
    anilistSource()->update(['enabled' => false]);
    fakeAniListPage();

    $this->artisan('bookflix:sync anilist')->expectsOutputToContain('disabled')->assertFailed();
    expect(IngestionRun::count())->toBe(0);
    Http::assertNothingSent();

    $this->artisan('bookflix:sync anilist --force')->assertSuccessful();
    expect(IngestionRun::count())->toBe(1);
});

test('a provider never has two syncs queued or running at once', function () {
    Queue::fake();

    SyncProvider::dispatch('anilist');
    SyncProvider::dispatch('anilist');
    SyncProvider::dispatch('openlibrary');

    Queue::assertPushed(SyncProvider::class, 2);
    expect((new SyncProvider('anilist'))->uniqueId())->toBe('anilist');
});

test('redis retry_after outlasts the longest horizon supervisor timeout', function () {
    $longest = collect(config('horizon.defaults'))->max('timeout');

    expect(config('queue.connections.redis.retry_after'))->toBeGreaterThan($longest);
});
