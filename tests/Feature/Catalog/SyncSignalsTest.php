<?php

use App\Models\ExternalSource;
use App\Models\Media;
use App\Models\MediaExternalId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    config(['bookflix.providers.wikidata.rate_per_minute' => 1000, 'bookflix.providers.wikipedia.rate_per_minute' => 1000]);
    $this->wikidata = ExternalSource::factory()->create(['key' => 'wikidata', 'enabled' => true]);
    $this->wikipedia = ExternalSource::factory()->create(['key' => 'wikipedia', 'enabled' => true]);
});

function mediaWithId(string $source, string $id): Media
{
    $media = Media::factory()->create();
    MediaExternalId::factory()->for($media)->create(['external_source_id' => (ExternalSource::where('key', $source)->first() ?? ExternalSource::factory()->create(['key' => $source]))->id, 'external_id' => $id]);

    return $media;
}

function binding(string $qid, string $property, string $value, ?string $article): array
{
    return array_filter([
        'item' => ['value' => "http://www.wikidata.org/entity/{$qid}"],
        'p' => ['value' => "http://www.wikidata.org/prop/direct/{$property}"],
        'value' => ['value' => $value],
        'article' => $article ? ['value' => $article] : null,
    ]);
}

test('wikidata links media by their provider ids with one query and remembers the check', function () {
    $berserk = mediaWithId('anilist', '30002');
    $dune = mediaWithId('openlibrary', 'OL893415W');
    $unknown = mediaWithId('mangaupdates', '55099564912');
    $linked = mediaWithId('anilist', '5');
    $linked->externalIds()->create(['external_source_id' => $this->wikidata->id, 'external_id' => 'Q1', 'payload_hash' => str_repeat('a', 64)]);

    Http::fake(['query.wikidata.org/sparql' => Http::response(['results' => ['bindings' => [
        binding('Q131', 'P8731', '30002', 'https://en.wikipedia.org/wiki/Berserk_(manga)'),
        binding('Q190', 'P648', 'OL893415W', null),
    ]]])]);

    $this->artisan('bookflix:sync-signals', ['source' => 'wikidata'])->assertSuccessful();

    $ids = fn (Media $media) => $media->externalIds()->where('external_source_id', $this->wikidata->id)->first();

    expect($ids($berserk)->external_id)->toBe('Q131')
        ->and($ids($berserk)->source_url)->toBe('https://en.wikipedia.org/wiki/Berserk_(manga)')
        ->and($ids($dune)->external_id)->toBe('Q190')
        ->and($ids($dune)->source_url)->toBeNull()
        ->and($ids($unknown))->toBeNull()
        ->and(DB::table('media_signal_checks')->where('external_source_id', $this->wikidata->id)->pluck('media_id')->all())
        ->toEqualCanonicalizing([$berserk->id, $dune->id, $unknown->id]);

    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r->hasHeader('User-Agent', config('bookflix.provider_user_agent'))
        && str_contains($r['query'], '(wdt:P8731 "30002")')
        && str_contains($r['query'], '(wdt:P648 "OL893415W")')
        && str_contains($r['query'], '(wdt:P11149 "'.base_convert('55099564912', 10, 36).'")')
        && $r['format'] === 'json');
});

test('wikidata checks the media checked longest ago first and respects the batch size', function () {
    $old = mediaWithId('anilist', '1');
    $new = mediaWithId('anilist', '2');
    $never = mediaWithId('anilist', '3');
    DB::table('media_signal_checks')->insert([
        ['media_id' => $old->id, 'external_source_id' => $this->wikidata->id, 'checked_at' => now()->subDays(9)],
        ['media_id' => $new->id, 'external_source_id' => $this->wikidata->id, 'checked_at' => now()->subDay()],
    ]);
    Http::fake(['query.wikidata.org/sparql' => Http::response(['results' => ['bindings' => []]])]);

    $this->artisan('bookflix:sync-signals', ['source' => 'wikidata', '--batch' => 2]);

    Http::assertSent(fn ($r) => str_contains($r['query'], '"3"') && str_contains($r['query'], '"1"') && ! str_contains($r['query'], '"2"'));
});

test('wikipedia sums thirty days of views into pageviews_30d for media with an article and then scores them', function () {
    $linked = mediaWithId('anilist', '30002');
    $linked->externalIds()->create(['external_source_id' => $this->wikidata->id, 'external_id' => 'Q131', 'source_url' => 'https://en.wikipedia.org/wiki/Berserk_(manga)', 'payload_hash' => str_repeat('a', 64)]);
    $noArticle = mediaWithId('anilist', '7');
    $noArticle->externalIds()->create(['external_source_id' => $this->wikidata->id, 'external_id' => 'Q7', 'payload_hash' => str_repeat('b', 64)]);

    Http::fake(['wikimedia.org/*' => Http::response(['items' => [['views' => 100], ['views' => 250]]])]);

    $this->artisan('bookflix:sync-signals', ['source' => 'wikipedia'])->assertSuccessful();

    expect(DB::table('media_metrics')->where('media_id', $linked->id)->where('external_source_id', $this->wikipedia->id)->where('metric', 'pageviews_30d')->value('value'))->toBe(350.0)
        ->and(DB::table('media_metrics')->where('media_id', $noArticle->id)->exists())->toBeFalse()
        ->and(DB::table('media_signal_checks')->where('media_id', $linked->id)->where('external_source_id', $this->wikipedia->id)->exists())->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(function ($r) {
        $end = now()->subDay()->format('Ymd');

        return str_contains($r->url(), '/per-article/en.wikipedia/all-access/user/Berserk_%28manga%29/daily/'.now()->subDays(30)->format('Ymd').'00/'.$end.'00');
    });
});

test('a page without pageview data is remembered as checked and records nothing', function () {
    $media = mediaWithId('anilist', '1');
    $media->externalIds()->create(['external_source_id' => $this->wikidata->id, 'external_id' => 'Q1', 'source_url' => 'https://en.wikipedia.org/wiki/Nothing', 'payload_hash' => str_repeat('a', 64)]);
    Http::fake(['wikimedia.org/*' => Http::response(['type' => 'not_found'], 404)]);

    $this->artisan('bookflix:sync-signals', ['source' => 'wikipedia'])->assertSuccessful();

    expect(DB::table('media_metrics')->count())->toBe(0)
        ->and(DB::table('media_signal_checks')->where('media_id', $media->id)->exists())->toBeTrue();
});

test('a disabled signal source is skipped unless forced and a throttled one fails the command', function () {
    mediaWithId('anilist', '1');
    $this->wikidata->update(['enabled' => false]);
    Http::fake(['query.wikidata.org/sparql' => Http::response('', 429, ['Retry-After' => '30'])]);

    $this->artisan('bookflix:sync-signals', ['source' => 'wikidata'])->assertSuccessful();
    Http::assertNothingSent();

    $this->artisan('bookflix:sync-signals', ['source' => 'wikidata', '--force' => true])->assertFailed();
    $this->artisan('bookflix:sync-signals', ['source' => 'nope'])->assertFailed();
    Http::assertSentCount(3);
    Sleep::assertSlept(fn ($duration) => $duration->totalSeconds === 30.0, 2);
});
