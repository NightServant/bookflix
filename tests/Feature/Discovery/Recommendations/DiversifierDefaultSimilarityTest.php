<?php

use App\Discovery\Recommendations\Diversifier;
use App\Models\Genre;
use App\Models\Media;
use App\Models\Theme;
use Illuminate\Support\Facades\DB;

test('the default similarity is genre and theme jaccard', function () {
    [$fantasy, $romance] = Genre::factory()->count(2)->create();
    [$a, $b, $c] = Media::factory()->count(3)->create();
    $a->genres()->attach($fantasy);
    $b->genres()->attach($fantasy);
    $c->genres()->attach($romance);

    $out = (new Diversifier)->rerank(collect([ranked($a->id, .9), ranked($b->id, .89), ranked($c->id, .5)]), 0.7, 2);

    expect($out->pluck('mediaId')->all())->toBe([$a->id, $c->id]);
});

test('themes count too, and media without any are unlike everything', function () {
    $theme = Theme::factory()->create();
    [$a, $b, $bare] = Media::factory()->count(3)->create();
    $a->themes()->attach($theme);
    $b->themes()->attach($theme);

    $out = (new Diversifier)->rerank(collect([ranked($a->id, .9), ranked($b->id, .89), ranked($bare->id, .6)]), 0.7, 2);

    expect($out->pluck('mediaId')->all())->toBe([$a->id, $bare->id]);
});

test('the default similarity is loaded once, not per pair', function () {
    $media = Media::factory()->count(12)->create();
    $items = $media->map(fn ($m, $i) => ranked($m->id, 0.9 - $i * 0.01));

    DB::enableQueryLog();
    (new Diversifier)->rerank($items, 0.7, 10);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(1);
});
