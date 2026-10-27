<?php

use App\Catalog\Data\NormalizedMedia;
use App\Catalog\Data\ProviderRecord;
use App\Catalog\Ingestion\Deduplicator;
use App\Catalog\Ingestion\Enricher;
use App\Catalog\Ingestion\IngestionPipeline;
use App\Catalog\Ingestion\RecordValidator;
use App\Catalog\Providers\MetadataProvider;
use App\Enums\ImportOutcome;
use App\Jobs\ClassifyMedia;
use App\Models\Creator;
use App\Models\DuplicateCandidate;
use App\Models\ExternalSource;
use App\Models\Media;
use App\Models\MediaExternalId;
use Database\Seeders\MediaTypeSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

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

test('a new media remembers the provider that created it as its primary', function () {
    importFixture('anilist', 'manga-berserk');

    expect(Media::first()->primary_external_source_id)->toBe(ExternalSource::firstWhere('key', 'anilist')->id);
});

test('a media merged away since the external id was read is updated through its winner', function () {
    importFixture('anilist', 'manga-berserk');
    $loser = Media::first();
    $winner = Media::factory()->manga()->create(['title' => 'Berserk (kept)']);
    $loser->update(['merged_into_id' => $winner->id]);
    $loser->delete();

    expect(importFixture('anilist', 'manga-berserk', mutate: ['description' => 'Fresh blurb']))->toBe(ImportOutcome::Updated)
        ->and(Media::count())->toBe(1)
        ->and($winner->fresh()->description)->toBe('Fresh blurb')
        ->and(MediaExternalId::sole()->media_id)->toBe($winner->id)
        ->and(Media::withTrashed()->find($loser->id)->description)->not->toBe('Fresh blurb');
});

test('a plainly removed media is not resurrected by a sync', function () {
    importFixture('anilist', 'manga-berserk');
    Media::first()->delete();

    expect(importFixture('anilist', 'manga-berserk', mutate: ['description' => 'x']))->toBe(ImportOutcome::Skipped)->and(Media::count())->toBe(0);
});

test('a match merged away before the store step hands over to the winner', function () {
    importFixture('anilist', 'manga-berserk');
    $loser = Media::first();
    $winner = Media::factory()->manga()->create(['title' => 'Winner']);
    $loser->update(['merged_into_id' => $winner->id]);
    $loser->delete();
    $this->partialMock(Deduplicator::class)->shouldReceive('match')->andReturn($loser);
    ExternalSource::factory()->create(['key' => 'mangadex']);

    $mangadex = Mockery::mock(MetadataProvider::class);
    $mangadex->shouldReceive('key')->andReturn('mangadex');
    $mangadex->shouldReceive('normalize')->andReturn(normalized(title: 'Berserk', type: 'manga', year: 1990, provider: 'mangadex'));

    $outcome = app(IngestionPipeline::class)->import(new ProviderRecord('mangadex', 'abc', ['id' => 'abc'], null, now()->toImmutable()), $mangadex);

    expect($outcome)->toBe(ImportOutcome::Merged)
        ->and(MediaExternalId::where('external_id', 'abc')->sole()->media_id)->toBe($winner->id)
        ->and(Media::count())->toBe(1);
});

test('urls that are not https on an allowed host and markup in descriptions never reach storage', function () {
    importFixture('anilist', 'manga-berserk', mutate: [
        'siteUrl' => 'javascript:alert(1)',
        'coverImage' => ['extraLarge' => 'http://s4.anilist.co/cover.jpg', 'large' => null],
        'description' => 'Fine &lt;script&gt;alert(1)&lt;/script&gt;<b>text</b>',
    ]);

    expect(Media::first()->cover_url)->toBeNull()
        ->and(Media::first()->description)->toBe('Fine alert(1)text')
        ->and(MediaExternalId::sole()->source_url)->toBeNull();

    importFixture('anilist', 'manga-berserk');

    expect(Media::first()->cover_url)->toStartWith('https://s4.anilist.co/')
        ->and(MediaExternalId::sole()->source_url)->toBe('https://anilist.co/manga/30002');
});

test('provider authority survives a merge, so the lowest external id row no longer decides', function () {
    $mangadex = ExternalSource::factory()->create(['key' => 'mangadex']);
    $loser = Media::factory()->manga()->create(['title' => 'Berserk', 'description' => 'MangaDex text', 'primary_external_source_id' => $mangadex->id]);
    $loser->externalIds()->save(MediaExternalId::factory()->make(['external_source_id' => $mangadex->id, 'external_id' => 'abc']));
    importFixture('anilist', 'manga-berserk', mutate: ['title' => ['english' => 'Unrelated Winner Title'], 'staff' => ['edges' => []]]);
    $winner = Media::where('title', 'Unrelated Winner Title')->sole();

    app(Deduplicator::class)->merge($winner, $loser);
    importFixture('anilist', 'manga-berserk', mutate: ['title' => ['english' => 'Unrelated Winner Title'], 'staff' => ['edges' => []], 'description' => 'AniList text']);

    expect($winner->fresh()->externalIds()->orderBy('id')->first()->external_source_id)->toBe($mangadex->id)
        ->and($winner->fresh()->primary_external_source_id)->toBe(ExternalSource::firstWhere('key', 'anilist')->id)
        ->and($winner->fresh()->description)->toBe('AniList text');
});

test('records with a title, creator or publisher name over 255 characters are skipped', function (array $mutate) {
    expect(importFixture('anilist', 'manga-berserk', mutate: $mutate))->toBe(ImportOutcome::Skipped)->and(Media::count())->toBe(0);
})->with([
    'title' => [['title' => ['english' => str_repeat('a', 256)]]],
    'title slug' => [['title' => ['english' => str_repeat('ß', 200)]]],
    'creator' => [['staff' => ['edges' => [['role' => 'Story & Art', 'node' => ['name' => ['full' => str_repeat('n', 256)]]]]]]],
]);

test('a publisher name over 255 characters is skipped', function () {
    $record = new ProviderRecord('anilist', '1', [], null, now()->toImmutable());
    app(MediaTypeSeeder::class)->run();
    $long = new NormalizedMedia(title: 'Fine', mediaTypeSlug: 'novel', publishers: [str_repeat('p', 256)]);
    $fine = new NormalizedMedia(title: str_repeat('a', 255), mediaTypeSlug: 'novel', publishers: [str_repeat('p', 255)]);

    expect(app(RecordValidator::class)->validate($record, $long))->toBeFalse()->and(app(RecordValidator::class)->validate($record, $fine))->toBeTrue();
});

test('the dewey code of each provider is kept on its external id and a merged import is classified too', function () {
    $existing = Media::factory()->manga()->create(['title' => 'Berserk', 'publication_year' => 1989]);
    $existing->creators()->attach(Creator::factory()->create(['name' => 'Kentarou Miura']), ['role' => 'author', 'position' => 0]);
    MediaExternalId::factory()->for($existing)->create(['external_source_id' => ExternalSource::factory()->create(['key' => 'anilist']), 'external_id' => '30002']);
    ExternalSource::factory()->create(['key' => 'mangadex']);
    Bus::fake([ClassifyMedia::class]);

    $mangadex = Mockery::mock(MetadataProvider::class);
    $mangadex->shouldReceive('key')->andReturn('mangadex');
    $mangadex->shouldReceive('normalize')->andReturn(normalized(title: 'Berserk', type: 'manga', year: 1990, creators: ['Kentarou Miura'], provider: 'mangadex', deweyCode: '741.5'));

    $outcome = app(IngestionPipeline::class)->import(new ProviderRecord('mangadex', 'abc', ['id' => 'abc'], null, now()->toImmutable()), $mangadex);

    expect($outcome)->toBe(ImportOutcome::Merged)
        ->and(MediaExternalId::where('external_id', 'abc')->sole()->dewey_code)->toBe('741.5');
    Bus::assertDispatched(ClassifyMedia::class, fn (ClassifyMedia $job) => $job->mediaId === $existing->id);
});

test('metrics are stored per source and fill the rating columns from every source', function () {
    $import = function (string $key, string $id, array $metrics): void {
        ExternalSource::where('key', $key)->exists() || ExternalSource::factory()->create(['key' => $key]);
        $provider = Mockery::mock(MetadataProvider::class);
        $provider->shouldReceive('key')->andReturn($key);
        $provider->shouldReceive('normalize')->andReturn(normalized(title: 'Berserk', type: 'manga', year: 1990, creators: ['Kentarou Miura'], provider: $key, metrics: $metrics));
        app(IngestionPipeline::class)->import(new ProviderRecord($key, $id, ['id' => $id, 'metrics' => $metrics], null, now()->toImmutable()), $provider);
    };

    $import('anilist', '1', ['popularity' => 500, 'ratings_count' => 100, 'average_rating' => 4.0]);
    $import('mangadex', 'abc', ['follows' => 900, 'ratings_count' => 300, 'average_rating' => 3.0]);
    $import('anilist', '1', ['popularity' => 700, 'ratings_count' => 100, 'average_rating' => 4.0]);

    $media = Media::sole();

    expect(DB::table('media_metrics')->count())->toBe(6)
        ->and((float) DB::table('media_metrics')->where('metric', 'popularity')->value('value'))->toBe(700.0)
        ->and($media->ratings_count)->toBe(400)
        ->and((float) $media->average_rating)->toBe(3.25);

    $media->update(['ratings_count' => 7, 'locked_fields' => ['ratings_count']]);
    $import('mangadex', 'abc', ['follows' => 901, 'ratings_count' => 301, 'average_rating' => 3.0]);

    expect($media->fresh()->ratings_count)->toBe(7);
});
