<?php

use App\Actions\Ratings\RecalculateRatedMedia;
use App\Jobs\RecalculateMediaRating;
use App\Models\Media;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Stories each rated by the reader and by one other active reader, with the aggregate already built from both.
 *
 * @return array{0: User, 1: list<Media>}
 */
function ratedByTwo(int $stories = 2, int $mine = 10, int $theirs = 4): array
{
    $reader = User::factory()->create();
    $other = User::factory()->create();
    $media = Media::factory()->count($stories)->create()->all();

    foreach ($media as $story) {
        Rating::factory()->create(['user_id' => $reader->id, 'media_id' => $story->id, 'score' => $mine]);
        Rating::factory()->create(['user_id' => $other->id, 'media_id' => $story->id, 'score' => $theirs]);
        (new RecalculateMediaRating($story->id))->handle();
    }

    return [$reader, $media];
}

test('deactivating an account takes the reader out of the stories they rated', function () {
    [$reader, [$first, $second]] = ratedByTwo();
    expect(bookflixMetric($first, 'ratings_count'))->toBe(2.0)->and(bookflixMetric($first, 'average_rating'))->toBe(3.5);

    $this->actingAs($reader);
    Livewire::test('pages::settings.deactivate-account-modal')->set('password', 'password')->call('deactivateAccount')->assertHasNoErrors();

    foreach ([$first, $second] as $story) {
        expect(bookflixMetric($story, 'ratings_count'))->toBe(1.0)
            ->and(bookflixMetric($story, 'average_rating'))->toBe(2.0)
            ->and($story->fresh()->ratings_count)->toBe(1);
    }
});

test('bringing a deactivated account back puts the reader into those stories again', function () {
    [$reader, [$story]] = ratedByTwo(1);
    $reader->forceFill(['deactivated_at' => now()])->save();
    expect(bookflixMetric($story, 'ratings_count'))->toBe(1.0);

    $reader->forceFill(['deactivated_at' => null])->save();

    expect(bookflixMetric($story, 'ratings_count'))->toBe(2.0)->and(bookflixMetric($story, 'average_rating'))->toBe(3.5);
});

test('only a change to deactivated_at queues anything, and only for the stories that reader rated', function () {
    [$reader] = ratedByTwo(3);
    $bystander = User::factory()->create();
    Rating::factory()->create(['user_id' => $bystander->id, 'media_id' => Media::factory()->create()->id]);
    Queue::fake();

    $reader->forceFill(['name' => 'Someone Else'])->save();
    Queue::assertNothingPushed();

    $reader->forceFill(['deactivated_at' => now()])->save();
    Queue::assertPushed(RecalculateMediaRating::class, 3);
    expect(Queue::pushed(RecalculateMediaRating::class)->map(fn (RecalculateMediaRating $job): int => $job->mediaId)->sort()->values()->all())
        ->toBe(Rating::where('user_id', $reader->id)->pluck('media_id')->sort()->values()->all());
});

test('purging a deactivated account leaves the stories it rated without its scores', function () {
    [$reader, [$story]] = ratedByTwo(1);
    DB::table('users')->where('id', $reader->id)->update(['deactivated_at' => now()->subDays(31)]);
    DB::table('media_metrics')->where('media_id', $story->id)->update(['value' => DB::raw('value + 1')]); // an aggregate that went stale before deactivation was wired

    $this->artisan('bookflix:purge-deactivated-accounts')->assertSuccessful();

    expect(User::find($reader->id))->toBeNull()
        ->and(Rating::where('media_id', $story->id)->count())->toBe(1)
        ->and(bookflixMetric($story, 'ratings_count'))->toBe(1.0)
        ->and(bookflixMetric($story, 'average_rating'))->toBe(2.0)
        ->and($story->fresh()->ratings_count)->toBe(1);
});

test('purge leaves a reader still in the retention window and their stories alone', function () {
    [$reader, [$story]] = ratedByTwo(1);
    DB::table('users')->where('id', $reader->id)->update(['deactivated_at' => now()->subDays(5)]);
    Queue::fake();

    $this->artisan('bookflix:purge-deactivated-accounts')->assertSuccessful();

    Queue::assertNothingPushed();
    expect(User::find($reader->id))->not->toBeNull()->and(Rating::where('media_id', $story->id)->count())->toBe(2);
});

test('the stories are queued a chunk at a time, one job per story', function () {
    [$reader] = ratedByTwo(5);
    Queue::fake();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, 'from "ratings"')) {
            $queries[] = $query->sql;
        }
    });

    app(RecalculateRatedMedia::class)->handle($reader, chunk: 2);

    Queue::assertPushed(RecalculateMediaRating::class, 5);
    expect($queries)->toHaveCount(3);
});

test('a reader who rated nothing queues nothing', function () {
    Queue::fake();

    app(RecalculateRatedMedia::class)->handle(User::factory()->create());
    User::factory()->create()->forceFill(['deactivated_at' => now()])->save();

    Queue::assertNothingPushed();
});

test('another reader\'s stories are never queued', function () {
    [$reader] = ratedByTwo(1);
    $bystander = User::factory()->create();
    $elsewhere = Rating::factory()->create(['user_id' => $bystander->id])->media_id;
    Queue::fake();

    app(RecalculateRatedMedia::class)->handle($reader);

    Queue::assertNotPushed(RecalculateMediaRating::class, fn (RecalculateMediaRating $job): bool => $job->mediaId === $elsewhere);
    Queue::assertPushed(RecalculateMediaRating::class, 1);
});
