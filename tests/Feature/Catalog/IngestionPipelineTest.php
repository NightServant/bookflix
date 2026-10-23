<?php

use App\Catalog\Data\ProviderRecord;
use App\Catalog\Ingestion\Enricher;
use App\Catalog\Ingestion\IngestionPipeline;
use App\Catalog\Providers\MetadataProvider;
use App\Enums\ImportOutcome;
use App\Jobs\ImportMediaRecord;
use App\Models\Creator;
use App\Models\DuplicateCandidate;
use App\Models\ExternalSource;
use App\Models\Media;
use App\Models\MediaExternalId;
use Database\Seeders\MediaTypeSeeder;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\RateLimiter;

test('importing creates media with creators genres and an external id', function () {
    $outcome = importFixture('anilist', 'manga-berserk');

    expect($outcome)->toBe(ImportOutcome::Created)
        ->and(Media::count())->toBe(1)
        ->and(MediaExternalId::where('external_id', '30002')->exists())->toBeTrue()
        ->and(Media::first()->search_text)->toContain('berserk')
        ->and(Media::first()->creators->pluck('name'))->toContain('Kentarou Miura');
});

test('re-importing the same payload changes nothing', function () {
    importFixture('anilist', 'manga-berserk');
    $before = Media::first()->updated_at;
    $this->travel(1)->minute();

    expect(importFixture('anilist', 'manga-berserk'))->toBe(ImportOutcome::Skipped)
        ->and(Media::count())->toBe(1)
        ->and(Media::first()->updated_at->equalTo($before))->toBeTrue();
});

test('a changed payload updates the media and keeps one external id row', function () {
    importFixture('anilist', 'manga-berserk');
    expect(importFixture('anilist', 'manga-berserk', mutate: ['description' => 'New blurb']))->toBe(ImportOutcome::Updated)
        ->and(MediaExternalId::count())->toBe(1)
        ->and(Media::first()->description)->toBe('New blurb');
});

test('locked fields are not overwritten by a sync', function () {
    importFixture('anilist', 'manga-berserk');
    Media::first()->update(['title' => 'Berserk (admin)', 'locked_fields' => ['title']]);

    importFixture('anilist', 'manga-berserk', mutate: ['description' => 'x']);

    expect(Media::first()->title)->toBe('Berserk (admin)')
        ->and(Media::first()->description)->toBe('x');
});

test('invalid records are skipped and never created', function (array $mutate) {
    expect(importFixture('anilist', 'manga-berserk', mutate: $mutate))->toBe(ImportOutcome::Skipped)->and(Media::count())->toBe(0);
})->with([
    'blank title' => [['title' => ['romaji' => ' ', 'english' => null, 'native' => null]]],
    'no type' => [['format' => 'UNKNOWN_FORMAT']],
    'no external id' => [['id' => null]],
    'adult' => [['isAdult' => true]],
]);

test('a failure inside the store step rolls everything back', function () {
    $this->mock(Enricher::class)->shouldReceive('apply')->andThrow(new RuntimeException('boom'));
    expect(fn () => importFixture('anilist', 'manga-berserk'))->toThrow(RuntimeException::class);
    expect(Media::count())->toBe(0)->and(MediaExternalId::count())->toBe(0)->and(Creator::count())->toBe(0);
});

test('unmapped provider terms land in tags, mapped ones in genres', function () {
    importFixture('anilist', 'manga-berserk');
    expect(Media::first()->genres->pluck('slug'))->toContain('fantasy')
        ->and(Media::first()->tags->pluck('slug'))->toContain('male-protagonist')->not->toContain('fantasy');
});

test('a second provider reporting the same work merges into one media without overwriting it', function () {
    importFixture('anilist', 'manga-berserk');
    Media::first()->update(['description' => 'Kept']);
    ExternalSource::factory()->create(['key' => 'mangadex']);

    $mangadex = Mockery::mock(MetadataProvider::class);
    $mangadex->shouldReceive('key')->andReturn('mangadex');
    $mangadex->shouldReceive('normalize')->andReturn(normalized(title: 'Berserk', type: 'manga', year: 1990, creators: ['Kentarou Miura'], provider: 'mangadex', description: 'Other provider text'));

    $outcome = app(IngestionPipeline::class)->import(new ProviderRecord('mangadex', 'abc', ['id' => 'abc'], null, now()->toImmutable()), $mangadex);

    expect($outcome)->toBe(ImportOutcome::Merged)
        ->and(Media::count())->toBe(1)
        ->and(Media::first()->externalIds()->count())->toBe(2)
        ->and(Media::first()->description)->toBe('Kept');
});

test('a near duplicate becomes a review candidate once', function () {
    importFixture('anilist', 'manga-berserk', mutate: ['title' => ['english' => 'Fullmetal Alchemist Brotherhood'], 'staff' => ['edges' => []]]);
    importFixture('anilist', 'manga-berserk', mutate: ['id' => 2, 'title' => ['english' => 'Fullmetal Alchemist Brotherhood Omnibus'], 'staff' => ['edges' => []]]);

    expect(Media::count())->toBe(2)
        ->and(DuplicateCandidate::count())->toBe(1);
});

test('the import job runs the pipeline on the ingestion queue under the provider rate limit', function () {
    app(MediaTypeSeeder::class)->run();
    ExternalSource::factory()->create(['key' => 'anilist']);
    $record = new ProviderRecord('anilist', '30002', providerFixture('anilist/manga-berserk.json'), null, now()->toImmutable());

    $job = new ImportMediaRecord($record, 'anilist');
    $job->handle(app(IngestionPipeline::class));

    expect($job->queue)->toBe('ingestion')
        ->and($job->backoff())->toBe([10, 60, 300])
        ->and($job->middleware()[0])->toBeInstanceOf(RateLimited::class)
        ->and(Media::count())->toBe(1)
        ->and(RateLimiter::limiter('provider:anilist'))->not->toBeNull();
});
