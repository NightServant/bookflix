<?php

use App\Jobs\RefreshOutsideReviews;
use App\Models\ExternalRating;
use App\Models\ExternalReview;
use App\Models\ExternalSource;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\MediaExternalId;
use App\Models\User;
use App\Reviews\OutsideReviews;
use Database\Seeders\MediaTypeSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    app(MediaTypeSeeder::class)->run();
    config(['bookflix.providers.hardcover.api_token' => 'test-token']);
});

function linkedMedia(string ...$sources): Media
{
    $media = Media::factory()->create();
    $ids = ['anilist' => '30002', 'hardcover' => '77', 'openlibrary' => 'OL1W', 'mangaupdates' => '55'];

    foreach ($sources as $key) {
        $source = ExternalSource::firstOrCreate(['key' => $key], ['name' => $key, 'base_url' => "https://{$key}.test", 'enabled' => true, 'attribution' => $key]);
        MediaExternalId::factory()->create(['media_id' => $media->id, 'external_source_id' => $source->id, 'external_id' => $ids[$key]]);
    }

    return $media;
}

function fakeAllProviders(array $override = []): void
{
    Http::fake([
        'graphql.anilist.co' => $override['anilist'] ?? Http::response(['data' => ['Media' => ['siteUrl' => 'https://anilist.co/manga/30002', 'averageScore' => 90, 'reviews' => ['nodes' => [
            ['id' => 1, 'summary' => 'One', 'score' => 80, 'private' => false, 'siteUrl' => 'https://anilist.co/review/1', 'createdAt' => 1_700_000_000, 'user' => ['name' => 'a']],
            ['id' => 2, 'summary' => 'Two', 'score' => 70, 'private' => false, 'siteUrl' => 'https://anilist.co/review/2', 'createdAt' => 1_700_000_100, 'user' => ['name' => 'b']],
        ]]]]]),
        'api.hardcover.app/*' => $override['hardcover'] ?? Http::response(['data' => ['books' => [['slug' => 'dune', 'rating' => 4, 'ratings_count' => 9, 'user_books' => [
            ['id' => 9, 'rating' => 5, 'review_raw' => 'Hardcover review', 'review_has_spoilers' => false, 'reviewed_at' => null, 'user' => ['username' => 'h']],
        ]]]]]),
        'openlibrary.org/*' => $override['openlibrary'] ?? Http::response(['summary' => ['average' => 3.5, 'count' => 4]]),
        'api.mangaupdates.com/*' => $override['mangaupdates'] ?? Http::response(['series_id' => 55, 'bayesian_rating' => 8, 'rating_votes' => 10, 'url' => 'https://www.mangaupdates.com/series/x']),
    ]);
}

test('the job stores each source reviews and ratings and stamps fetched_at', function () {
    $media = linkedMedia('anilist', 'hardcover', 'openlibrary', 'mangaupdates');
    fakeAllProviders();

    (new RefreshOutsideReviews($media->id))->handle();

    expect(ExternalReview::where('media_id', $media->id)->pluck('source')->sort()->values()->all())->toBe(['anilist', 'anilist', 'hardcover'])
        ->and(ExternalRating::where('media_id', $media->id)->orderBy('source')->get()->map(fn ($r) => [$r->source, $r->score, $r->votes])->all())
        ->toBe([['anilist', 90, null], ['hardcover', 80, 9], ['mangaupdates', 80, 10], ['openlibrary', 70, 4]])
        ->and(ExternalReview::first()->fetched_at->isToday())->toBeTrue();
});

test('the job runs on the ingestion queue and is unique per media until it starts', function () {
    $job = new RefreshOutsideReviews(5);

    expect($job->queue)->toBe('ingestion')
        ->and($job->uniqueId())->toBe('5')
        ->and($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
});

test('a refresh updates changed reviews, keeps a hide and deletes the ones no longer returned unless hidden', function () {
    $media = linkedMedia('anilist');
    $moderator = User::factory()->create();
    $hiddenAt = now()->subDay()->startOfSecond();
    ExternalReview::factory()->create(['media_id' => $media->id, 'source' => 'anilist', 'external_id' => '1', 'excerpt' => 'old', 'hidden_at' => $hiddenAt, 'hidden_by' => $moderator->id, 'fetched_at' => now()->subDays(40)]);
    ExternalReview::factory()->create(['media_id' => $media->id, 'source' => 'anilist', 'external_id' => 'gone']);
    ExternalReview::factory()->hidden()->create(['media_id' => $media->id, 'source' => 'anilist', 'external_id' => 'gone-hidden']);
    $other = ExternalReview::factory()->create(['media_id' => $media->id, 'source' => 'hardcover', 'external_id' => 'gone']);
    fakeAllProviders();

    (new RefreshOutsideReviews($media->id))->handle();

    $kept = ExternalReview::where('source', 'anilist')->where('external_id', '1')->first();

    expect($kept->excerpt)->toBe('One')
        ->and($kept->hidden_at->equalTo($hiddenAt))->toBeTrue()
        ->and($kept->hidden_by)->toBe($moderator->id)
        ->and($kept->fetched_at->isToday())->toBeTrue()
        ->and(ExternalReview::where('source', 'anilist')->pluck('external_id')->sort()->values()->all())->toBe(['1', '2', 'gone-hidden'])
        ->and($other->fresh())->not->toBeNull();
});

test('a source that no longer has a rating loses it, and a source that fails keeps rows and does not fail the others', function () {
    $media = linkedMedia('anilist', 'openlibrary', 'mangaupdates');
    ExternalReview::factory()->create(['media_id' => $media->id, 'source' => 'anilist', 'external_id' => '1', 'excerpt' => 'kept']);
    ExternalRating::factory()->create(['media_id' => $media->id, 'source' => 'anilist', 'score' => 12]);
    ExternalRating::factory()->create(['media_id' => $media->id, 'source' => 'openlibrary', 'score' => 33]);
    fakeAllProviders([
        'anilist' => Http::response('', 500),
        'openlibrary' => Http::response(['summary' => ['average' => null, 'count' => 0]]),
    ]);

    (new RefreshOutsideReviews($media->id))->handle();

    expect(ExternalReview::where('source', 'anilist')->value('excerpt'))->toBe('kept')
        ->and(ExternalRating::where(['media_id' => $media->id, 'source' => 'anilist'])->value('score'))->toBe(12)
        ->and(ExternalRating::where(['media_id' => $media->id, 'source' => 'openlibrary'])->exists())->toBeFalse()
        ->and(ExternalRating::where(['media_id' => $media->id, 'source' => 'mangaupdates'])->value('score'))->toBe(80);
});

test('a timeout, a throttle, malformed JSON or a missing token each leave that source alone', function (string $source, Closure $response) {
    $media = linkedMedia($source);
    ExternalReview::factory()->create(['media_id' => $media->id, 'source' => $source, 'external_id' => 'keep']);
    ExternalRating::factory()->create(['media_id' => $media->id, 'source' => $source, 'score' => 44]);
    config(['bookflix.providers.hardcover.api_token' => $source === 'hardcover' && $response() === null ? '' : 'test-token']);
    Http::fake(['*' => $response() ?? Http::response('')]);

    $job = new RefreshOutsideReviews($media->id);
    $job->handle();

    expect(ExternalReview::where('source', $source)->pluck('external_id')->all())->toBe(['keep'])
        ->and(ExternalRating::where('source', $source)->value('score'))->toBe(44);
})->with([
    'timeout' => ['anilist', fn () => Http::failedConnection()],
    'throttled' => ['openlibrary', fn () => Http::response('', 429)],
    'malformed' => ['mangaupdates', fn () => Http::response('{not json', 200)],
    'html page' => ['anilist', fn () => Http::response('<html></html>', 200)],
    'no token' => ['hardcover', fn () => null],
]);

test('disabled sources, sources without an id for the media and providers that offer nothing are not called', function () {
    $media = linkedMedia('anilist', 'openlibrary');
    ExternalSource::where('key', 'anilist')->update(['enabled' => false]);
    ExternalSource::factory()->create(['key' => 'mangadex', 'enabled' => true]);
    MediaExternalId::factory()->create(['media_id' => $media->id, 'external_source_id' => ExternalSource::firstWhere('key', 'mangadex')->id, 'external_id' => 'x']);
    fakeAllProviders();

    (new RefreshOutsideReviews($media->id))->handle();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://openlibrary.org/works/OL1W/ratings.json');
});

test('a source whose provider rate limit is used up is not called', function () {
    $media = linkedMedia('openlibrary');
    fakeAllProviders();
    $limit = RateLimiter::limiter('provider:openlibrary')();
    $bucket = md5('provider:openlibrary'.$limit->key);

    foreach (range(1, $limit->maxAttempts) as $ignored) {
        RateLimiter::hit($bucket, 60);
    }

    (new RefreshOutsideReviews($media->id))->handle();

    Http::assertNothingSent();
});

test('every request goes to the provider API host and every stored link to an allowed host', function () {
    $media = linkedMedia('anilist', 'hardcover', 'openlibrary', 'mangaupdates');
    fakeAllProviders();

    (new RefreshOutsideReviews($media->id))->handle();

    $apiHosts = ['graphql.anilist.co', 'api.hardcover.app', 'openlibrary.org', 'api.mangaupdates.com'];
    $allowed = collect(['anilist', 'hardcover', 'openlibrary', 'mangaupdates'])->flatMap(fn ($key) => config("bookflix.providers.{$key}.allowed_hosts"))->all();

    Http::assertSentCount(4);
    Http::assertSent(fn ($request) => in_array(parse_url($request->url(), PHP_URL_HOST), $apiHosts, true));

    foreach (ExternalReview::pluck('url')->merge(ExternalRating::whereNotNull('url')->pluck('url')) as $url) {
        expect(parse_url($url, PHP_URL_SCHEME))->toBe('https')->and($allowed)->toContain(parse_url($url, PHP_URL_HOST));
    }
});

test('a deleted or merged media is skipped', function () {
    $media = linkedMedia('openlibrary');
    fakeAllProviders();
    $media->delete();

    (new RefreshOutsideReviews($media->id))->handle();

    Http::assertNothingSent();
});

test('refreshIfStale queues when data is missing or older than the stale days, and not when fresh', function () {
    Bus::fake();

    $missing = Media::factory()->create();
    $old = Media::factory()->create();
    $fresh = Media::factory()->create();
    $rated = Media::factory()->create();
    ExternalRating::factory()->create(['media_id' => $old->id, 'fetched_at' => now()->subDays(31)]);
    ExternalReview::factory()->create(['media_id' => $old->id, 'fetched_at' => now()->subDays(60)]);
    ExternalReview::factory()->create(['media_id' => $fresh->id, 'fetched_at' => now()->subDays(5)]);
    ExternalRating::factory()->create(['media_id' => $rated->id, 'fetched_at' => now()->subDays(5)]);

    expect(OutsideReviews::refreshIfStale($missing))->toBeTrue()
        ->and(OutsideReviews::refreshIfStale($old))->toBeTrue()
        ->and(OutsideReviews::refreshIfStale($fresh))->toBeFalse()
        ->and(OutsideReviews::refreshIfStale($rated))->toBeFalse();

    config(['bookflix.reviews.outside_stale_days' => 3]);

    expect(OutsideReviews::refreshIfStale($fresh))->toBeTrue();
    Bus::assertDispatchedTimes(RefreshOutsideReviews::class, 3);
});

test('refreshIfStale queues at most once a day per media', function () {
    Bus::fake();
    $media = Media::factory()->create();
    $other = Media::factory()->create();

    expect(OutsideReviews::refreshIfStale($media))->toBeTrue()
        ->and(OutsideReviews::refreshIfStale($media))->toBeFalse()
        ->and(OutsideReviews::refreshIfStale($other))->toBeTrue();

    Bus::assertDispatchedTimes(RefreshOutsideReviews::class, 2);
});

test('the story page helpers return visible reviews newest first and ratings by source', function () {
    $media = Media::factory()->create();
    $old = ExternalReview::factory()->create(['media_id' => $media->id, 'published_at' => now()->subYear()]);
    $new = ExternalReview::factory()->create(['media_id' => $media->id, 'published_at' => now()->subDay()]);
    ExternalReview::factory()->hidden()->create(['media_id' => $media->id, 'published_at' => now()]);
    ExternalReview::factory()->create(['media_id' => Media::factory()->create()->id]);
    ExternalRating::factory()->create(['media_id' => $media->id, 'source' => 'openlibrary']);
    ExternalRating::factory()->create(['media_id' => $media->id, 'source' => 'anilist']);

    expect(OutsideReviews::visibleReviews($media)->pluck('id')->all())->toBe([$new->id, $old->id])
        ->and(OutsideReviews::ratings($media)->keys()->all())->toBe(['anilist', 'openlibrary'])
        ->and($media->externalReviews()->count())->toBe(3)
        ->and($media->externalRatings()->count())->toBe(2);
});

test('the command queues the most-read stories up to the limit', function () {
    Bus::fake();
    $top = Media::factory()->create();
    $middle = Media::factory()->create();
    $least = Media::factory()->create();
    Media::factory()->create();

    foreach ([[$top, 3], [$middle, 2], [$least, 1]] as [$media, $readers]) {
        User::factory()->count($readers)->create()->each(fn (User $user) => LibraryEntry::create(['user_id' => $user->id, 'media_id' => $media->id]));
    }

    Artisan::call('bookflix:refresh-outside-reviews', ['--limit' => 2]);

    Bus::assertDispatchedTimes(RefreshOutsideReviews::class, 2);
    Bus::assertDispatched(RefreshOutsideReviews::class, fn ($job) => $job->mediaId === $top->id);
    Bus::assertDispatched(RefreshOutsideReviews::class, fn ($job) => $job->mediaId === $middle->id);
    Bus::assertNotDispatched(RefreshOutsideReviews::class, fn ($job) => $job->mediaId === $least->id);
});

test('the nightly run is scheduled and follows the config switch, off by default', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'bookflix:refresh-outside-reviews'));

    expect(config('bookflix.reviews.outside_enabled'))->toBeFalse()
        ->and($event)->not->toBeNull()
        ->and($event->command)->toContain('--limit=500')
        ->and($event->filtersPass(app()))->toBeFalse();

    config(['bookflix.reviews.outside_enabled' => true]);

    expect($event->filtersPass(app()))->toBeTrue();
});
