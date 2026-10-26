<?php

use App\Classification\ClassShelves;
use App\Models\Media;
use Database\Seeders\DeweySeeder;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->seed(DeweySeeder::class));

test('stories are grouped by root and by division', function () {
    $a = classified(Media::factory()->create(), '813');
    $b = classified(Media::factory()->create(), '823');
    $c = classified(Media::factory()->create(), '510');
    $shelves = app(ClassShelves::class);

    expect($shelves->forMedia(collect([$a, $b, $c]))->map(fn ($s) => $s->class->code)->all())->toBe(['500', '800'])
        ->and($shelves->forMedia(collect([$a, $b, $c]), 'division')->map(fn ($s) => $s->class->code)->all())->toBe(['510', '810', '820']);
});

test('an empty collection and unclassified media produce no shelves', function () {
    expect(app(ClassShelves::class)->forMedia(collect()))->toBeEmpty()
        ->and(app(ClassShelves::class)->forMedia(collect([Media::factory()->create()])))->toBeEmpty();
});

test('hidden classifications do not create shelves', function () {
    $m = classified(Media::factory()->create(), '813', status: 'rejected');
    expect(app(ClassShelves::class)->forMedia(collect([$m])))->toBeEmpty();
});

test('building shelves for 20 stories runs a constant number of queries', function () {
    $media = Media::factory()->count(20)->create()->each(fn ($m) => classified($m, '813'));
    DB::enableQueryLog();
    app(ClassShelves::class)->forMedia($media->fresh());
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(3);
});
