<?php

use App\Discovery\Recommendations\SeedItems;
use App\Models\Media;
use App\Models\Rating;
use App\Models\User;

function rated(User $user, Media $media, int $score): Rating
{
    return Rating::factory()->create(['user_id' => $user->id, 'media_id' => $media->id, 'score' => $score]);
}

test('strength follows favorite, rating, completed and reading, the strongest applying', function () {
    $user = User::factory()->create();
    [$favorite, $rated, $completed, $reading, $wanted, $ratedAndCompleted, $favoriteRated] = Media::factory()->count(7)->create();
    libraryEntry($user, $favorite, 'reading', favorite: true);
    rated($user, $rated, 7);
    libraryEntry($user, $completed, 'completed');
    libraryEntry($user, $reading, 'reading');
    libraryEntry($user, $wanted, 'want_to_read');
    libraryEntry($user, $ratedAndCompleted, 'completed');
    rated($user, $ratedAndCompleted, 10);
    libraryEntry($user, $favoriteRated, 'completed', favorite: true);
    rated($user, $favoriteRated, 6);

    $seeds = app(SeedItems::class)->for($user);

    expect($seeds->all())->toEqualCanonicalizing([
        $favorite->id => 1.0,
        $rated->id => 0.7,
        $completed->id => 0.8,
        $reading->id => 0.5,
        $ratedAndCompleted->id => 1.0,
        $favoriteRated->id => 1.0,
    ])->and($seeds->has($wanted->id))->toBeFalse()
        ->and($seeds->values()->first())->toBe(1.0);
});

test('abandoned and low rated stories are not seeds whatever else they are', function () {
    $user = User::factory()->create();
    [$abandoned, $abandonedFavorite, $low, $lowFavorite, $edge] = Media::factory()->count(5)->create();
    libraryEntry($user, $abandoned, 'abandoned');
    libraryEntry($user, $abandonedFavorite, 'abandoned', favorite: true);
    libraryEntry($user, $low, 'completed');
    rated($user, $low, 4);
    libraryEntry($user, $lowFavorite, 'completed', favorite: true);
    rated($user, $lowFavorite, 2);
    rated($user, $edge, 5);

    expect(app(SeedItems::class)->for($user)->keys()->all())->toBe([$edge->id]);
});

test('other readers and deleted or merged stories do not count', function () {
    $user = User::factory()->create();
    [$mine, $theirs, $deleted, $merged] = Media::factory()->count(4)->create();
    libraryEntry($user, $mine, 'completed');
    libraryEntry(User::factory()->create(), $theirs, 'completed');
    libraryEntry($user, $deleted, 'completed');
    libraryEntry($user, $merged, 'completed');
    $deleted->delete();
    $merged->update(['merged_into_id' => $mine->id]);

    expect(app(SeedItems::class)->for($user)->keys()->all())->toBe([$mine->id]);
});

test('most recent is the latest completed or highly rated story', function () {
    $user = User::factory()->create();
    [$old, $new, $loved] = Media::factory()->count(3)->create();
    expect(app(SeedItems::class)->mostRecent($user))->toBeNull();

    libraryEntry($user, $old, 'completed')->update(['completed_at' => now()->subDays(10)]);
    libraryEntry($user, $new, 'completed')->update(['completed_at' => now()->subDays(2)]);
    expect(app(SeedItems::class)->mostRecent($user)->id)->toBe($new->id);

    rated($user, $loved, 7);
    expect(app(SeedItems::class)->mostRecent($user)->id)->toBe($new->id);

    Rating::where('user_id', $user->id)->where('media_id', $loved->id)->update(['score' => 9, 'updated_at' => now()]);
    expect(app(SeedItems::class)->mostRecent($user)->id)->toBe($loved->id);
});

test('a reader with nothing has no seeds', function () {
    $user = User::factory()->create();

    expect(app(SeedItems::class)->for($user))->toBeEmpty()
        ->and(app(SeedItems::class)->mostRecent($user))->toBeNull();
});
