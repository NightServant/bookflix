<?php

use App\Discovery\Recommendations\Sources\ExplorationSource;
use App\Enums\ReasonType;
use App\Models\Genre;
use App\Models\Media;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Database\Eloquent\Collection;
use Random\Engine\Mt19937;
use Random\Randomizer;

function exploration(int $seed = 42): ExplorationSource
{
    return app()->makeWith(ExplorationSource::class, ['random' => new Randomizer(new Mt19937($seed))]);
}

function wellRated(int $count, array $attributes = []): Collection
{
    return Media::factory()->count($count)->create(['average_rating' => 4.2, 'ratings_count' => 10, ...$attributes]);
}

test('the pick is deterministic for a seeded randomizer and avoids the top genres', function () {
    $user = User::factory()->create();
    $liked = Genre::factory()->create();
    UserPreference::create(['user_id' => $user->id, 'preferable_type' => 'genre', 'preferable_id' => $liked->id]);
    wellRated(10)->each(fn (Media $media) => $media->genres()->attach($liked));
    $others = wellRated(10);

    $first = exploration()->candidatesFor($user, 4);
    $again = exploration()->candidatesFor($user, 4);

    expect($first)->toHaveCount(4)
        ->and($first->pluck('mediaId')->all())->toBe($again->pluck('mediaId')->all())
        ->and($first->pluck('mediaId')->diff($others->pluck('id'))->all())->toBe([])
        ->and($first->first()->reason)->toBe(ReasonType::Exploration)
        ->and($first->first()->source)->toBe('exploration');
});

test('well rated means 3.75 or better from at least five ratings, then it falls back', function () {
    $user = User::factory()->create();
    $good = Media::factory()->create(['average_rating' => 3.75, 'ratings_count' => 5]);
    $thin = Media::factory()->create(['average_rating' => 5.0, 'ratings_count' => 4]);
    $mediocre = Media::factory()->create(['average_rating' => 3.7, 'ratings_count' => 50]);
    $unrated = Media::factory()->create();

    expect(exploration()->candidatesFor($user, 1)->pluck('mediaId')->all())->toBe([$good->id]);

    $all = exploration()->candidatesFor($user, 10)->pluck('mediaId')->all();

    expect($all)->toHaveCount(4)->and($all[0])->toBe($good->id)
        ->and(array_slice($all, 1))->toContain($thin->id, $mediocre->id, $unrated->id);
});

test('with nothing rated it still returns media', function () {
    $user = User::factory()->create();
    $media = Media::factory()->count(3)->create();

    expect(exploration()->candidatesFor($user, 10)->pluck('mediaId')->sort()->values()->all())->toBe($media->pluck('id')->sort()->values()->all());
});

test('library, dismissed, deleted and merged media are excluded', function () {
    $user = User::factory()->create();
    [$inLib, $dismissed, $deleted, $merged, $ok] = wellRated(5)->all();
    libraryEntry($user, $inLib, 'reading');
    dismissed($user, $dismissed);
    $deleted->delete();
    $merged->update(['merged_into_id' => $ok->id]);

    expect(exploration()->candidatesFor($user, 10)->pluck('mediaId')->all())->toBe([$ok->id]);
});

test('a mature story is left out until the reader opts in', function () {
    $mature = matureStory();
    $mature->update(['average_rating' => 4.5, 'ratings_count' => 20]);

    expect(exploration()->candidatesFor(User::factory()->create(), 10))->toBeEmpty()
        ->and(exploration()->candidatesFor(User::factory()->showsMature()->create(), 10)->pluck('mediaId')->all())->toBe([$mature->id]);
});
