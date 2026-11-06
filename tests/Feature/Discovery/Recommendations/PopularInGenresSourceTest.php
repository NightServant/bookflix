<?php

use App\Discovery\Recommendations\Sources\PopularInGenresSource;
use App\Enums\ReasonType;
use App\Models\Genre;
use App\Models\Media;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;

function likes(User $user, Genre $genre, int $weight = 1): void
{
    UserPreference::create(['user_id' => $user->id, 'preferable_type' => 'genre', 'preferable_id' => $genre->id, 'weight' => $weight]);
}

test('the best known stories of the favorite genres come first, carrying the genre', function () {
    $user = User::factory()->create();
    [$fantasy, $romance, $horror] = Genre::factory()->count(3)->sequence(['name' => 'Fantasy'], ['name' => 'Romance'], ['name' => 'Horror'])->create();
    likes($user, $fantasy, 3);
    likes($user, $romance, 1);
    [$big, $small, $inRomance, $outside] = Media::factory()->count(4)->sequence(['ratings_count' => 90], ['ratings_count' => 10], ['ratings_count' => 50], ['ratings_count' => 500])->create();
    $big->genres()->attach($fantasy);
    $small->genres()->attach($fantasy);
    $inRomance->genres()->attach($romance);
    $outside->genres()->attach($horror);
    DB::statement('refresh materialized view media_popularity');

    $c = app(PopularInGenresSource::class)->candidatesFor($user, 10);

    expect($c->pluck('mediaId')->all())->toBe([$big->id, $inRomance->id, $small->id])
        ->and($c->first()->payload)->toBe(['genre' => 'Fantasy'])
        ->and($c->get(1)->payload)->toBe(['genre' => 'Romance'])
        ->and($c->first()->reason)->toBe(ReasonType::MatchesInterest)
        ->and($c->first()->source)->toBe('popular_in_genres');
});

test('genres of the seeds count by their strength', function () {
    $user = User::factory()->create();
    $genre = Genre::factory()->create(['name' => 'Mystery']);
    $seed = Media::factory()->create();
    $seed->genres()->attach($genre);
    libraryEntry($user, $seed, 'completed');
    $new = Media::factory()->create();
    $new->genres()->attach($genre);
    DB::statement('refresh materialized view media_popularity');

    expect(app(PopularInGenresSource::class)->candidatesFor($user, 10)->pluck('payload')->all())->toBe([['genre' => 'Mystery']]);
});

test('only the top three genres are used', function () {
    $user = User::factory()->create();
    $genres = Genre::factory()->count(4)->create();
    foreach ($genres as $i => $genre) {
        likes($user, $genre, 4 - $i);
    }
    $last = Media::factory()->create();
    $last->genres()->attach($genres[3]);
    DB::statement('refresh materialized view media_popularity');

    expect(app(PopularInGenresSource::class)->candidatesFor($user, 10))->toBeEmpty();
});

test('library, dismissed, deleted and merged media are excluded', function () {
    $user = User::factory()->create();
    $genre = Genre::factory()->create();
    likes($user, $genre);
    [$inLib, $dismissed, $deleted, $merged, $ok] = Media::factory()->count(5)->create();
    foreach ([$inLib, $dismissed, $deleted, $merged, $ok] as $media) {
        $media->genres()->attach($genre);
    }
    libraryEntry($user, $inLib, 'want_to_read');
    dismissed($user, $dismissed);
    $deleted->delete();
    $merged->update(['merged_into_id' => $ok->id]);
    DB::statement('refresh materialized view media_popularity');

    expect(app(PopularInGenresSource::class)->candidatesFor($user, 10)->pluck('mediaId')->all())->toBe([$ok->id]);
});

test('a mature story is left out until the reader opts in', function () {
    $mature = matureStory();
    $genre = Genre::factory()->create();
    $mature->genres()->attach($genre);
    $gated = User::factory()->create();
    $open = User::factory()->showsMature()->create();
    likes($gated, $genre);
    likes($open, $genre);
    DB::statement('refresh materialized view media_popularity');

    expect(app(PopularInGenresSource::class)->candidatesFor($gated, 10))->toBeEmpty()
        ->and(app(PopularInGenresSource::class)->candidatesFor($open, 10)->pluck('mediaId')->all())->toBe([$mature->id]);
});

test('a reader with no leanings gets nothing', function () {
    Media::factory()->create();

    expect(app(PopularInGenresSource::class)->candidatesFor(User::factory()->create(), 10))->toBeEmpty();
});
