<?php

use App\Catalog\Data\ProviderRecord;
use App\Catalog\Providers\OpenLibrary\OpenLibraryProvider;
use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Enums\CoverPolicy;
use App\Enums\CreatorRole;
use App\Models\ExternalSource;
use Carbon\CarbonImmutable;
use Database\Seeders\ExternalSourceSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;

beforeEach(function () {
    Http::fake(['openlibrary.org/authors/OL79034A.json' => Http::response(providerFixture('openlibrary/author-herbert.json'))]);
});

test('a work and edition normalize to NormalizedMedia', function () {
    Http::fake([
        'openlibrary.org/works/OL893415W.json' => Http::response(providerFixture('openlibrary/work-dune.json')),
        'openlibrary.org/works/OL893415W/editions.json*' => Http::response(providerFixture('openlibrary/edition-dune.json')),
    ]);

    $record = app(OpenLibraryProvider::class)->find('OL893415W');
    $media = app(OpenLibraryProvider::class)->normalize($record);

    expect($record->provider)->toBe('openlibrary')
        ->and($media->title)->toBe('Dune')
        ->and($media->mediaTypeSlug)->toBe('novel')
        ->and($media->isbns)->toContain('9780441172719')
        ->and($media->externalDeweyCode)->toBe('813.54')
        ->and($media->creators[0]->name)->toBe('Frank Herbert')
        ->and($media->creators[0]->role)->toBe(CreatorRole::Author)
        ->and($media->coverUrl)->toStartWith('https://covers.openlibrary.org/')
        ->and($media->genres)->not->toBeEmpty();
    Http::assertSent(fn ($r) => $r->hasHeader('User-Agent', config('bookflix.provider_user_agent')));
});

test('find returns null for a 404', function () {
    Http::fake(['openlibrary.org/*' => Http::response(null, 404)]);
    expect(app(OpenLibraryProvider::class)->find('OL0W'))->toBeNull();
});

test('find follows a merged work to its canonical id', function () {
    Http::fake([
        'openlibrary.org/works/OL893415W.json' => Http::response(['type' => ['key' => '/type/redirect'], 'location' => '/works/OL893414W']),
        'openlibrary.org/works/OL893414W.json' => Http::response(providerFixture('openlibrary/work-dune.json')),
        'openlibrary.org/works/OL893414W/editions.json*' => Http::response(providerFixture('openlibrary/edition-dune.json')),
    ]);

    expect(app(OpenLibraryProvider::class)->find('OL893415W')->externalId)->toBe('OL893414W');
});

test('changedSince yields records lazily from the recent-changes feed', function () {
    Http::fake(['openlibrary.org/recentchanges*' => Http::response(providerFixture('openlibrary/recent-changes.json')), 'openlibrary.org/works/*' => Http::response(providerFixture('openlibrary/work-dune.json'))]);

    $records = app(OpenLibraryProvider::class)->changedSince(CarbonImmutable::parse('2026-10-01'));

    expect($records)->toBeInstanceOf(LazyCollection::class)->and($records->first())->toBeInstanceOf(ProviderRecord::class);
});

test('a malformed payload normalizes to an empty title so the validator can reject it', function () {
    $media = app(OpenLibraryProvider::class)->normalize(new ProviderRecord('openlibrary', 'OL1W', providerFixture('openlibrary/work-malformed.json'), null, now()->toImmutable()));
    expect($media->title)->toBe('');
});

test('a 429 surfaces as a RateLimitedByProvider exception carrying retry-after', function () {
    Http::fake(['openlibrary.org/works/*' => Http::response(null, 429, ['Retry-After' => '30'])]);

    expect(fn () => app(OpenLibraryProvider::class)->find('OL893415W'))
        ->toThrow(fn (RateLimitedByProvider $e) => expect($e->retryAfter)->toBe(30));
});

test('a 5xx surfaces as ProviderUnavailable', function () {
    Http::fake(['openlibrary.org/works/*' => Http::response(null, 503)]);

    expect(fn () => app(OpenLibraryProvider::class)->find('OL893415W'))->toThrow(ProviderUnavailable::class);
});

test('provider sources are seeded disabled until config enables them', function () {
    $this->seed(ExternalSourceSeeder::class);

    expect(ExternalSource::where('enabled', true)->count())->toBe(0)
        ->and(ExternalSource::firstWhere('key', 'openlibrary')->cover_policy)->toBe(CoverPolicy::Hotlink);

    config(['bookflix.providers.anilist.enabled' => true]);
    $this->seed(ExternalSourceSeeder::class);

    expect(ExternalSource::firstWhere('key', 'anilist')->enabled)->toBeTrue()
        ->and(ExternalSource::count())->toBe(2);
});
