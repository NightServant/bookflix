<?php

use App\Ai\TitleVerifier;
use App\Models\Creator;
use App\Models\Media;
use Illuminate\Support\Facades\DB;

function verifier(): TitleVerifier
{
    return app(TitleVerifier::class);
}

function credited(Media $media, string $name): Media
{
    $media->creators()->attach(Creator::factory()->create(['name' => $name]), ['role' => 'author', 'position' => 0]);

    return $media;
}

test('an exact title resolves, ignoring case and quotes', function () {
    $media = Media::factory()->create(['title' => 'Dune']);

    expect(verifier()->resolve('"dune"')?->is($media))->toBeTrue();
});

test('accents do not matter either way', function () {
    $media = Media::factory()->create(['title' => 'Pokémon Adventures']);

    expect(verifier()->resolve('Pokemon Adventures')?->is($media))->toBeTrue();
});

test('an alternative title and a CJK title resolve', function () {
    $media = Media::factory()->create(['title' => 'Attack on Titan', 'alternative_titles' => ['Shingeki no Kyojin', '進撃の巨人']]);

    expect(verifier()->resolve('Shingeki no Kyojin')?->is($media))->toBeTrue()
        ->and(verifier()->resolve('進撃の巨人')?->is($media))->toBeTrue();
});

test('a typo resolves, a punctuation difference resolves, a longer title does not', function () {
    $media = Media::factory()->create(['title' => 'Berserk']);
    $long = Media::factory()->create(['title' => 'The Rising of the Shield Hero']);
    Media::factory()->create(['title' => 'Something Else Entirely']);

    expect(verifier()->resolve('the rising of the Shield-Hero!')?->is($long))->toBeTrue()
        ->and(verifier()->resolve('The Rising of the Sheild Hero')?->is($long))->toBeTrue()
        ->and(verifier()->resolve('Beserk')?->is($media))->toBeTrue()
        ->and(verifier()->resolve('Berserker Chronicles'))->toBeNull()
        ->and(verifier()->resolve('Never Heard Of It'))->toBeNull();
});

test('titles under six characters get no typo tolerance', function () {
    Media::factory()->create(['title' => 'Akira']);
    $monster = Media::factory()->create(['title' => 'Monster']);

    expect(verifier()->resolve('Akia'))->toBeNull()
        ->and(verifier()->resolve('Monter')?->is($monster))->toBeTrue();
});

test('the creator picks between two stories with the same title', function () {
    $manga = credited(Media::factory()->manga()->create(['title' => 'Monster']), 'Naoki Urasawa');
    $novel = credited(Media::factory()->novel()->create(['title' => 'Monster']), 'Walter Dean Myers');

    expect(verifier()->resolve('Monster', 'Walter Dean Myers')?->is($novel))->toBeTrue()
        ->and(verifier()->resolve('Monster', 'naoki urasawa')?->is($manga))->toBeTrue()
        ->and(verifier()->resolve('Monster', 'Somebody Else'))->toBeNull();
});

test('a merged media resolves to its winner and a deleted one to nothing', function () {
    $winner = Media::factory()->create(['title' => 'Winner Story']);
    $loser = Media::factory()->create(['title' => 'Loser Story', 'merged_into_id' => $winner->id]);
    $loser->delete();
    $gone = Media::factory()->create(['title' => 'Vanished Story']);
    $gone->delete();

    expect(verifier()->resolve('Loser Story')?->is($winner))->toBeTrue()
        ->and(verifier()->resolve('Vanished Story'))->toBeNull();
});

test('scrub drops a sentence that names an invented title and keeps the rest', function () {
    $real = Media::factory()->create(['title' => 'Berserk']);

    $out = verifier()->scrub('Start with "Berserk" for dark fantasy. Then read "The Phantom Archive" next. Enjoy.', collect([$real]));

    expect($out)->toBe('Start with "Berserk" for dark fantasy. Enjoy.');
});

test('scrub keeps a verified title and turns an emptied list item into a notice', function () {
    $real = Media::factory()->create(['title' => 'Berserk']);

    $out = verifier()->scrub("- Berserk by Kentaro Miura\n- *Imaginary Tales* is brilliant.\n\nA closing line.", collect([$real]));

    expect($out)->toBe("- Berserk by Kentaro Miura\n- I couldn't find that in the catalog.\n\nA closing line.");
});

test('a verified title outside the allowed list is removed', function () {
    $real = Media::factory()->create(['title' => 'Berserk']);

    expect(verifier()->scrub('Read "Berserk".', collect()))->toBe("I couldn't find that in the catalog.");
});

test('scrub handles empty and very long input', function () {
    expect(verifier()->scrub('  ', collect()))->toBe('');

    $long = str_repeat('Plain filler sentence. ', 2000);

    expect(mb_strlen(verifier()->scrub($long, collect())))->toBeLessThanOrEqual(20000);
});

test('ten mentions resolve in a bounded number of queries', function () {
    $titles = collect(range(1, 10))->map(fn (int $i) => Media::factory()->create(['title' => "Unique Saga Number {$i}x"])->title);

    DB::enableQueryLog();
    $resolved = verifier()->resolveMany($titles->map(fn (string $title) => ['title' => $title, 'creator' => null])->all());
    $queries = count(DB::getQueryLog());

    expect($queries)->toBeLessThanOrEqual(4)
        ->and(collect($resolved)->filter()->count())->toBe(10);
});
