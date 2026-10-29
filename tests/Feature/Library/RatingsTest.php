<?php

use App\Actions\Ratings\RateMedia;
use App\Catalog\MediaRatings;
use App\Events\LibraryChanged;
use App\Jobs\RecalculateMediaRating;
use App\Models\ExternalSource;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\Rating;
use App\Models\User;
use App\Models\UserInteraction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('a user has one rating per story and re-rating updates it', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();

    app(RateMedia::class)->handle($user, $media, 7);
    app(RateMedia::class)->handle($user, $media, 9);

    expect(Rating::count())->toBe(1)->and(Rating::sole()->score)->toBe(9);
});

test('scores outside 1 to 10 are rejected and nothing is stored or queued', function (int $score) {
    Queue::fake();

    try {
        app(RateMedia::class)->handle(User::factory()->create(), Media::factory()->create(), $score);
        $this->fail('No ValidationException was thrown.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('score');
    }

    expect(Rating::count())->toBe(0)->and(UserInteraction::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([0, 11, -3, PHP_INT_MAX]);

test('the database rejects a score of 0 or 11, and a second row for one reader and story', function () {
    $row = fn (int $score, ?User $user = null, ?Media $media = null): array => ['user_id' => ($user ?? User::factory()->create())->id, 'media_id' => ($media ?? Media::factory()->create())->id, 'score' => $score, 'created_at' => now(), 'updated_at' => now()];

    foreach ([0, 11] as $score) {
        expect(fn () => DB::transaction(fn () => DB::table('ratings')->insert($row($score))))->toThrow(QueryException::class);
    }

    [$user, $media] = [User::factory()->create(), Media::factory()->create()];
    DB::table('ratings')->insert($row(5, $user, $media));

    expect(fn () => DB::transaction(fn () => DB::table('ratings')->insert($row(6, $user, $media))))->toThrow(QueryException::class)
        ->and(DB::table('ratings')->count())->toBe(1);
});

test('the component rejects a half point score', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('library-controls', ['media' => Media::factory()->create()])->set('rating', 5.5)->call('saveRating')->assertHasErrors(['rating']);

    expect(Rating::count())->toBe(0);
});

test('the aggregate is recomputed from active users only', function () {
    $media = Media::factory()->create();

    foreach ([[8, false], [6, false], [10, true]] as [$score, $deactivated]) {
        $reader = User::factory()->create(['deactivated_at' => $deactivated ? now() : null]);
        Rating::factory()->create(['user_id' => $reader->id, 'media_id' => $media->id, 'score' => $score]);
    }

    (new RecalculateMediaRating($media->id))->handle();

    // The readers' mean is 7 of 10, which is 3.5 on the 0-5 metric scale; no other source, so the blend is the readers' aggregate.
    expect(bookflixMetric($media, 'average_rating'))->toBe(3.5)
        ->and(bookflixMetric($media, 'ratings_count'))->toBe(2.0)
        ->and((float) $media->fresh()->average_rating)->toBe(3.5)
        ->and($media->fresh()->ratings_count)->toBe(2);
});

test('readers blend with provider ratings by count', function () {
    $media = Media::factory()->create();
    providerMetrics($media, 'anilist', ['average_rating' => 4.0, 'ratings_count' => 2]);
    Rating::factory()->create(['media_id' => $media->id, 'score' => 6]);
    Rating::factory()->create(['media_id' => $media->id, 'score' => 6]);

    (new RecalculateMediaRating($media->id))->handle();

    // (4.0 x 2 + 3.0 x 2) / 4, and 2 + 2 ratings.
    expect((float) $media->fresh()->average_rating)->toBe(3.5)->and($media->fresh()->ratings_count)->toBe(4);
});

test('removing the last rating drops the readers aggregate and keeps the provider average', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    providerMetrics($media, 'anilist', ['average_rating' => 4.0, 'ratings_count' => 10]);

    app(RateMedia::class)->handle($user, $media, 2);

    expect(bookflixMetric($media, 'average_rating'))->toBe(1.0)->and($media->fresh()->ratings_count)->toBe(11);

    app(RateMedia::class)->forget($user, $media);

    expect(bookflixMetric($media, 'average_rating'))->toBeNull()
        ->and(bookflixMetric($media, 'ratings_count'))->toBeNull()
        ->and((float) $media->fresh()->average_rating)->toBe(4.0)
        ->and($media->fresh()->ratings_count)->toBe(10);
});

test('removing the last rating of a story no provider rated empties its rating', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();

    app(RateMedia::class)->handle($user, $media, 8);

    expect((float) $media->fresh()->average_rating)->toBe(4.0);

    app(RateMedia::class)->forget($user, $media);

    expect($media->fresh()->average_rating)->toBeNull()->and($media->fresh()->ratings_count)->toBe(0);
});

test('the aggregate rounds the readers mean to two decimals of the five point scale', function (array $scores, float $average) {
    $media = Media::factory()->create();

    foreach ($scores as $score) {
        Rating::factory()->create(['media_id' => $media->id, 'score' => $score]);
    }

    (new RecalculateMediaRating($media->id))->handle();

    expect(bookflixMetric($media, 'average_rating'))->toBe($average)->and(bookflixMetric($media, 'ratings_count'))->toBe((float) count($scores));
})->with([
    'one half star' => [[1], 0.5],
    'a top score' => [[10, 10], 5.0],
    'a third' => [[1, 2, 2], 0.83],
    'a quarter' => [[7, 8], 3.75],
]);

test('running the job twice changes nothing and keeps one pair of readers rows', function () {
    $media = Media::factory()->create();
    Rating::factory()->create(['media_id' => $media->id, 'score' => 9]);

    (new RecalculateMediaRating($media->id))->handle();
    (new RecalculateMediaRating($media->id))->handle();

    expect(DB::table('media_metrics')->where('media_id', $media->id)->count())->toBe(2)
        ->and(ExternalSource::where('key', 'bookflix')->count())->toBe(1)
        ->and((float) $media->fresh()->average_rating)->toBe(4.5);
});

test('only active readers count, and a story with only deactivated raters has no readers aggregate', function () {
    $media = Media::factory()->create();
    Rating::factory()->create(['user_id' => User::factory()->create(['deactivated_at' => now()])->id, 'media_id' => $media->id, 'score' => 10]);

    (new RecalculateMediaRating($media->id))->handle();

    expect(bookflixMetric($media, 'ratings_count'))->toBeNull()->and($media->fresh()->average_rating)->toBeNull();
});

test('a story whose readers never rated it keeps the rating columns it has, even with no metrics behind them', function () {
    $media = Media::factory()->create(['average_rating' => '4.20', 'ratings_count' => 30]);

    (new RecalculateMediaRating($media->id))->handle();

    expect(bookflixMetric($media, 'ratings_count'))->toBeNull()
        ->and((float) $media->fresh()->average_rating)->toBe(4.2)
        ->and($media->fresh()->ratings_count)->toBe(30);
});

test('a column an admin locked is not overwritten by the job', function () {
    $media = Media::factory()->create(['average_rating' => '2.00', 'ratings_count' => 40, 'locked_fields' => ['average_rating', 'ratings_count']]);
    Rating::factory()->create(['media_id' => $media->id, 'score' => 10]);

    (new RecalculateMediaRating($media->id))->handle();

    expect(bookflixMetric($media, 'average_rating'))->toBe(5.0)
        ->and((float) $media->fresh()->average_rating)->toBe(2.0)
        ->and($media->fresh()->ratings_count)->toBe(40);
});

test('the job leaves a story that is gone alone', function () {
    $media = Media::factory()->create();
    Rating::factory()->create(['media_id' => $media->id, 'score' => 4]);
    $media->delete();

    (new RecalculateMediaRating($media->id))->handle();
    (new RecalculateMediaRating(0))->handle();

    expect(DB::table('media_metrics')->count())->toBe(0);
});

test('the readers source is synthetic: disabled, named, and out of the providers an admin manages', function () {
    $media = Media::factory()->create();
    Rating::factory()->create(['media_id' => $media->id, 'score' => 4]);

    (new RecalculateMediaRating($media->id))->handle();

    $source = ExternalSource::where('key', 'bookflix')->sole();

    expect($source->enabled)->toBeFalse()
        ->and($source->name)->toBe('Bookflix readers')
        ->and(ExternalSource::providers()->where('key', 'bookflix')->exists())->toBeFalse()
        ->and(ExternalSource::providers()->where('key', 'isbn')->exists())->toBeFalse();
});

test('the readers source never becomes an attribution on the story', function () {
    $this->actingAs(User::factory()->create());
    $media = Media::factory()->create();
    app(RateMedia::class)->handle(User::factory()->create(), $media, 8);

    $this->get(route('stories.show', $media->slug))->assertOk()->assertDontSee('Bookflix readers')->assertDontSee('Data from');
});

test('rating dispatches the aggregate job and records an interaction value', function () {
    Queue::fake([RecalculateMediaRating::class]);
    $user = User::factory()->create();
    $media = Media::factory()->create();

    app(RateMedia::class)->handle($user, $media, 8);

    Queue::assertPushed(RecalculateMediaRating::class, fn (RecalculateMediaRating $job): bool => $job->mediaId === $media->id);
    expect((float) UserInteraction::where('event', 'rating')->sole()->value)->toBe(8.0);
});

test('saving the same score again is no new signal, and a new score is', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();

    foreach ([8, 8, 6] as $score) {
        app(RateMedia::class)->handle($user, $media, $score);
    }

    expect(UserInteraction::where('event', 'rating')->orderBy('id')->pluck('value')->map(fn ($value): float => (float) $value)->all())->toBe([8.0, 6.0]);
});

test('opted out users can rate without an interaction row', function () {
    $user = User::factory()->create();
    $user->profile()->updateOrCreate([], ['personalization_enabled' => false]);

    app(RateMedia::class)->handle($user, Media::factory()->create(), 8);

    expect(Rating::count())->toBe(1)->and(UserInteraction::count())->toBe(0);
});

test('rating does not put the story in the library, and leaves an entry it is in as it was', function () {
    $user = User::factory()->create();
    [$loose, $kept] = Media::factory()->count(2)->create();
    LibraryEntry::factory()->for($user)->for($kept)->create(['status' => 'paused']);

    app(RateMedia::class)->handle($user, $loose, 9);
    app(RateMedia::class)->handle($user, $kept, 9);

    expect(LibraryEntry::count())->toBe(1)->and(LibraryEntry::sole()->status->value)->toBe('paused');
});

test('rating and taking a rating back each announce a library change, and taking back a rating that is not there does not', function () {
    Event::fake([LibraryChanged::class]);
    $user = User::factory()->create();
    $media = Media::factory()->create();

    app(RateMedia::class)->handle($user, $media, 5);
    app(RateMedia::class)->forget($user, $media);
    app(RateMedia::class)->forget($user, $media);

    Event::assertDispatchedTimes(LibraryChanged::class, 2);
});

test('taking a rating back touches only that readers rating', function () {
    [$user, $other] = User::factory()->count(2)->create();
    $media = Media::factory()->create();
    app(RateMedia::class)->handle($user, $media, 4);
    app(RateMedia::class)->handle($other, $media, 10);

    app(RateMedia::class)->forget($user, $media);

    expect(Rating::sole()->user_id)->toBe($other->id)->and(bookflixMetric($media, 'average_rating'))->toBe(5.0);
});

test('deleting a reader or a story takes their ratings with it', function () {
    [$user, $other] = User::factory()->count(2)->create();
    [$media, $kept] = Media::factory()->count(2)->create();
    Rating::factory()->create(['user_id' => $user->id, 'media_id' => $kept->id]);
    Rating::factory()->create(['user_id' => $other->id, 'media_id' => $media->id]);

    $user->delete();
    $media->forceDelete();

    expect(Rating::count())->toBe(0);
});

test('the component shows only the signed in users rating', function () {
    $media = Media::factory()->create();
    Rating::factory()->create(['media_id' => $media->id, 'score' => 3]);
    $this->actingAs($reader = User::factory()->create());

    expect(Livewire::test('library-controls', ['media' => $media])->get('rating'))->toBeNull();

    Rating::factory()->create(['user_id' => $reader->id, 'media_id' => $media->id, 'score' => 7]);

    expect(Livewire::test('library-controls', ['media' => $media])->get('rating'))->toBe(7);
});

test('saving from the controls rates the story for the signed in reader only', function () {
    Queue::fake([RecalculateMediaRating::class]);
    $other = Rating::factory()->create(['score' => 2]);
    $this->actingAs($reader = User::factory()->create());
    $media = Media::factory()->create();

    Livewire::test('library-controls', ['media' => $media])->set('rating', 9)->call('saveRating')->assertHasNoErrors()->assertSet('rating', 9);

    expect(Rating::where('user_id', $reader->id)->sole()->score)->toBe(9)
        ->and(Rating::where('user_id', $other->user_id)->sole()->score)->toBe(2)
        ->and(LibraryEntry::count())->toBe(0);
    Queue::assertPushed(RecalculateMediaRating::class);
});

test('a score that is not a whole number from 1 to 10 is refused by the controls', function (mixed $value) {
    $this->actingAs(User::factory()->create());

    Livewire::test('library-controls', ['media' => Media::factory()->create()])->set('rating', $value)->call('saveRating')->assertHasErrors(['rating']);

    expect(Rating::count())->toBe(0);
})->with([0, 11, -1, 5.5, 'abc', '', null, [[3]], '7; drop table ratings', true]);

test('clearing from the controls removes only the readers own rating', function () {
    $media = Media::factory()->create();
    $other = Rating::factory()->create(['media_id' => $media->id, 'score' => 4]);
    $this->actingAs($reader = User::factory()->create());
    Rating::factory()->create(['user_id' => $reader->id, 'media_id' => $media->id, 'score' => 8]);

    Livewire::test('library-controls', ['media' => $media])->assertSet('rating', 8)->call('clearRating')->assertSet('rating', null);

    expect(Rating::sole()->user_id)->toBe($other->user_id);
});

test('a guest or an unverified reader cannot rate', function () {
    $media = Media::factory()->create();

    Livewire::test('library-controls', ['media' => $media])->set('rating', 5)->call('saveRating')->assertForbidden();
    Livewire::test('library-controls', ['media' => $media])->call('clearRating')->assertForbidden();

    $this->actingAs(User::factory()->unverified()->create());
    Livewire::test('library-controls', ['media' => $media])->set('rating', 5)->call('saveRating')->assertForbidden();

    expect(Rating::count())->toBe(0);
});

test('a story behind the mature gate cannot be rated by a reader who has not opted in, and can by one who has', function () {
    $story = matureStory();
    $this->actingAs(User::factory()->create());

    Livewire::test('library-controls', ['media' => $story])->set('rating', 6)->call('saveRating')->assertNotFound();
    expect(Rating::count())->toBe(0);

    $this->actingAs($opted = User::factory()->showsMature()->create());
    Livewire::test('library-controls', ['media' => $story])->set('rating', 6)->call('saveRating')->assertHasNoErrors();
    expect(Rating::sole()->user_id)->toBe($opted->id);
});

test('a story merged away or deleted cannot be rated', function () {
    $winner = Media::factory()->create();
    $merged = Media::factory()->create(['merged_into_id' => $winner->id]);
    $this->actingAs(User::factory()->create());

    Livewire::test('library-controls', ['media' => $merged])->set('rating', 6)->call('saveRating')->assertNotFound();

    expect(Rating::count())->toBe(0);
});

test('the controls ask for the rating once, when they open, and for the library entry once', function () {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();
    Rating::factory()->create(['user_id' => $user->id, 'media_id' => $media->id, 'score' => 5]);
    $user->load('profile');

    DB::enableQueryLog();
    $controls = Livewire::test('library-controls', ['media' => $media]);
    $queries = fn (string $table): int => collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], "\"{$table}\""))->count();

    expect($queries('ratings'))->toBe(1)->and($queries('user_library'))->toBe(1);

    DB::flushQueryLog();
    $controls->call('toggleFavorite');

    expect($queries('ratings'))->toBe(0);
});

test('the controls draw the rating input with all ten scores', function () {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();
    Rating::factory()->create(['user_id' => $user->id, 'media_id' => $media->id, 'score' => 7]);

    $html = Livewire::test('library-controls', ['media' => $media])->html();

    expect(htmlQuery($html, '//*[@role="radiogroup"][@aria-label="Your rating"]')->length)->toBe(1)
        ->and(collect(htmlQuery($html, '//*[@role="radiogroup"]//button[@role="radio"]'))->map(fn (DOMElement $radio): string => $radio->getAttribute('aria-label'))->all())
        ->toBe(array_map(fn (int $score): string => "{$score} out of 10", range(1, 10)));
});

test('a guest sees no rating input', function () {
    $html = Livewire::test('library-controls', ['media' => Media::factory()->create()])->html();

    expect(htmlQuery($html, '//*[@role="radiogroup"]')->length)->toBe(0);
});

test('the story page shows the blended average with the display and the readers own score in the controls', function () {
    $media = Media::factory()->create();
    providerMetrics($media, 'anilist', ['average_rating' => 4.0, 'ratings_count' => 2]);
    $this->actingAs($reader = User::factory()->create());

    app(RateMedia::class)->handle($reader, $media, 6);
    app(RateMedia::class)->handle(User::factory()->create(), $media, 6);

    $this->get(route('stories.show', $media->slug))->assertOk()
        ->assertSee('Rated 3.5 out of 5 from 4 ratings')
        ->assertSee('role="radiogroup"', false);
});

test('a story whose only rating is a provider average with no count shows that average, not "No ratings yet"', function () {
    $media = Media::factory()->create();
    providerMetrics($media, 'mangadex', ['average_rating' => 4.77]);
    MediaRatings::blend($media);
    $this->actingAs(User::factory()->create());

    expect($media->fresh()->ratings_count)->toBe(0);

    $this->get(route('stories.show', $media->slug))->assertOk()
        ->assertSee('Rated 4.8 out of 5', false)
        ->assertDontSee('No ratings yet')
        ->assertDontSee('from 0 ratings');
});
