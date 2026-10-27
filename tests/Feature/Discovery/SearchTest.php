<?php

use App\Console\Commands\RebuildSearchText;
use App\Discovery\Search\SearchTextBuilder;
use App\Models\Media;
use Database\Seeders\DeweySeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(DeweySeeder::class);

    $this->aot = Media::factory()->manga()->create(['title' => 'Attack on Titan', 'alternative_titles' => ['進撃の巨人', 'Shingeki no Kyojin']]);
    $this->berserk = Media::factory()->manga()->create(['title' => 'Berserk']);
    $this->pokemon = Media::factory()->create(['title' => 'Pokémon Adventures']);

    Media::query()->each(fn (Media $media) => $media->update(['search_text' => app(SearchTextBuilder::class)->build($media)]));
});

test('titles are found across scripts accents and alternative titles', function (string $q, string $expected) {
    expect(search($q)->pluck('title')->first())->toBe($expected);
})->with([
    ['進撃の巨人', 'Attack on Titan'],
    ['Shingeki no Kyojin', 'Attack on Titan'],
    ['Attack on Titan', 'Attack on Titan'],
    ['Pokemon', 'Pokémon Adventures'],
    ['Pokémon', 'Pokémon Adventures'],
    ['Berserl', 'Berserk'],
]);

test('the last word matches as a prefix while the reader is still typing', function (string $q) {
    $comics = Media::factory()->create(['title' => 'Japanese Comics Digest']);
    $comics->update(['search_text' => app(SearchTextBuilder::class)->build($comics)]);

    expect(search($q)->pluck('title'))->toContain('Japanese Comics Digest');
})->with(['japan', 'Japan', 'japanese com', 'comics dig']);

test('a fragment of a non latin title is found by substring', function () {
    expect(search('巨人')->pluck('title')->all())->toBe(['Attack on Titan']);
});

test('hostile or degenerate queries are safe', function (string $q) {
    expect(fn () => search($q))->not->toThrow(Throwable::class);
    expect(search($q)->total())->toBe(0);
})->with(['', '   ', '%', '_', '"', "'; drop table media;--", '!!!', str_repeat('a', 5000)]);

test('wildcards in a non latin query are literal', function () {
    expect(search('進%')->total())->toBe(0)
        ->and(search('進_')->total())->toBe(0);
});

test('soft deleted media is never returned', function () {
    $this->berserk->delete();

    expect(search('Berserk')->total())->toBe(0);
});

test('merged media is never returned', function () {
    $this->berserk->update(['merged_into_id' => $this->aot->id]);

    expect(search('Berserk')->total())->toBe(0);
});

test('the full text index is used by the generated query', function () {
    $sql = null;
    Media::search('titans')->query(function ($query) use (&$sql) {
        $sql = $query->toRawSql();
    })->take(1)->get();

    DB::statement('set local enable_seqscan = off');
    $plan = collect(DB::select('explain '.$sql))->pluck('QUERY PLAN')->implode("\n");

    expect($plan)->toContain('media_search_fts');
});

test('a subject name finds stories classified under it', function () {
    classified(Media::factory()->create(['title' => 'Some Novel']), '813');

    expect(search('literature')->pluck('title'))->toContain('Some Novel');
});

test('a class code finds stories classified at or below it', function () {
    classified(Media::factory()->create(['title' => 'Some Novel']), '813');

    expect(search('810')->pluck('title'))->toContain('Some Novel');
});

test('only a public primary class feeds the subject leg', function (string $type, string $status) {
    classified(Media::factory()->create(['title' => 'Hidden Novel']), '813', $type, $status);

    expect(search('literature')->pluck('title'))->not->toContain('Hidden Novel');
})->with([
    'secondary' => ['secondary', 'accepted'],
    'rejected' => ['primary', 'rejected'],
]);

test('legs are fused so a title match outranks a subject only match', function () {
    classified($this->berserk, '741.52');
    classified(Media::factory()->create(['title' => 'Japanese Comics Digest']), '741.52');

    expect(search('japanese comics')->pluck('title')->first())->toBe('Japanese Comics Digest')
        ->and(search('berserk')->first()->title)->toBe('Berserk');
});

test('search text keeps both the accented and the folded names and leaves cjk alone', function () {
    $text = app(SearchTextBuilder::class)->build($this->pokemon);
    $japanese = app(SearchTextBuilder::class)->build(Media::factory()->create(['title' => 'ガンダム']));

    expect($text)->toBe('pokémon adventures pokemon adventures')
        ->and($japanese)->toBe('ガンダム');
});

test('the rebuild command refills the search text', function () {
    Media::query()->update(['search_text' => '']);

    $this->artisan(RebuildSearchText::class)->assertSuccessful();

    expect($this->aot->fresh()->search_text)->toContain('shingeki no kyojin');
});

test('a search that fills a leg reports more results than it counts', function () {
    Media::factory()->count(101)->sequence(fn ($sequence) => ['title' => 'Zebra Tale '.$sequence->index, 'search_text' => 'zebra tale'])->create();

    expect(search('zebra')->hasMore)->toBeTrue()
        ->and(search('berserk')->hasMore)->toBeFalse();
});
