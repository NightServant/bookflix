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

/**
 * A recent-changes feed of `$count` additions, each touching its own work, newest first.
 *
 * @return list<array<string, mixed>>
 */
function recentChanges(int $count): array
{
    return array_map(fn (int $index): array => [
        'id' => (string) (900 - $index),
        'kind' => 'add-book',
        'timestamp' => CarbonImmutable::parse('2026-10-23 12:00:00', 'UTC')->subMinutes($index)->format('Y-m-d\TH:i:s.u'),
        'changes' => [['key' => '/works/OL'.(100 + $index).'W', 'revision' => 1]],
    ], range(0, $count - 1));
}

test('page reads one slice of the recent-changes feed, then moves on to edits, then ends', function () {
    Http::fake(['openlibrary.org/recentchanges*' => Http::response(providerFixture('openlibrary/recent-changes.json')), 'openlibrary.org/works/*' => Http::response(providerFixture('openlibrary/work-dune.json'))]);
    $provider = app(OpenLibraryProvider::class);

    $additions = $provider->page(CarbonImmutable::parse('2026-10-01'), null);
    $edits = $provider->page(CarbonImmutable::parse('2026-10-01'), $additions->nextPageToken);

    expect($additions->records)->not->toBeEmpty()->and($additions->records[0])->toBeInstanceOf(ProviderRecord::class)
        ->and($additions->nextPageToken)->toBe('edit-book:0')
        ->and($additions->maxUpdatedAt->toIso8601String())->toBe('2026-10-23T02:49:12+00:00')
        ->and($edits->nextPageToken)->toBeNull();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/recentchanges/add-book.json') && $r['offset'] === 0 && $r['limit'] === 10);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/recentchanges/edit-book.json'));
});

test('a full slice points at the next offset of the same feed', function () {
    Http::fake(['openlibrary.org/recentchanges*' => Http::response(recentChanges(10)), 'openlibrary.org/works/*' => Http::response(providerFixture('openlibrary/work-dune.json'))]);
    $provider = app(OpenLibraryProvider::class);

    $page = $provider->page(CarbonImmutable::parse('2026-10-01'), null);
    $provider->page(CarbonImmutable::parse('2026-10-01'), $page->nextPageToken);

    expect($page->nextPageToken)->toBe('add-book:10')->and($page->records)->toHaveCount(10);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/recentchanges/add-book.json') && $r['offset'] === 10);
});

test('works with neither a cover nor a description are skipped', function () {
    Http::fake(['openlibrary.org/recentchanges*' => Http::response(recentChanges(10)), 'openlibrary.org/works/*' => Http::response(['title' => 'A bare work', 'type' => ['key' => '/type/work']])]);

    $page = app(OpenLibraryProvider::class)->page(CarbonImmutable::parse('2026-10-01'), null);

    expect($page->records)->toBeEmpty()->and($page->nextPageToken)->toBe('add-book:10');
});

test('a slice that reaches the cursor stops reading that feed', function () {
    Http::fake(['openlibrary.org/recentchanges*' => Http::response(recentChanges(10)), 'openlibrary.org/works/*' => Http::response(providerFixture('openlibrary/work-dune.json'))]);

    $page = app(OpenLibraryProvider::class)->page(CarbonImmutable::parse('2026-10-23 11:57:30', 'UTC'), 'add-book:20');

    expect($page->records)->toHaveCount(3)->and($page->nextPageToken)->toBe('edit-book:0')
        ->and($page->maxUpdatedAt->toIso8601String())->toBe('2026-10-23T12:00:00+00:00');
});

test('cover and source urls are kept only when https on an allowed host, and descriptions carry no markup', function () {
    $payload = ['work' => [...providerFixture('openlibrary/work-dune.json'), 'description' => ['value' => 'Fremen &amp; spice &lt;script&gt;x()&lt;/script&gt;<i>sand</i>']], 'editions' => [], 'authors' => []];
    $record = fn (?string $url) => new ProviderRecord('openlibrary', 'OL1W', $payload, $url, now()->toImmutable());

    $media = app(OpenLibraryProvider::class)->normalize($record('https://openlibrary.org/works/OL1W'));

    expect($media->sourceUrl)->toBe('https://openlibrary.org/works/OL1W')->and($media->coverUrl)->toStartWith('https://covers.openlibrary.org/')
        ->and($media->description)->toBe('Fremen & spice x()sand');

    config(['bookflix.providers.openlibrary.allowed_hosts' => ['openlibrary.org']]);

    expect(app(OpenLibraryProvider::class)->normalize($record('http://openlibrary.org/works/OL1W'))->sourceUrl)->toBeNull()
        ->and(app(OpenLibraryProvider::class)->normalize($record('https://openlibrary.org/works/OL1W'))->coverUrl)->toBeNull();
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

test('seeding gives a new source its starting state from config and never overwrites enabled afterwards', function () {
    config(['bookflix.providers.anilist.enabled' => true]);
    $this->seed(ExternalSourceSeeder::class);

    expect(ExternalSource::firstWhere('key', 'openlibrary')->enabled)->toBeFalse()
        ->and(ExternalSource::firstWhere('key', 'openlibrary')->cover_policy)->toBe(CoverPolicy::Hotlink)
        ->and(ExternalSource::firstWhere('key', 'anilist')->enabled)->toBeTrue();

    ExternalSource::where('key', 'anilist')->update(['enabled' => false]);
    ExternalSource::where('key', 'openlibrary')->update(['enabled' => true]);
    $this->seed(ExternalSourceSeeder::class);

    expect(ExternalSource::firstWhere('key', 'anilist')->enabled)->toBeFalse()
        ->and(ExternalSource::firstWhere('key', 'openlibrary')->enabled)->toBeTrue()
        ->and(ExternalSource::count())->toBe(9);
});
