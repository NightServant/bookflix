<?php

use App\Actions\Library\AddToLibrary;
use App\Actions\Ratings\RateMedia;
use App\Discovery\Recommendations\RecommendationLogger;
use App\Discovery\Recommendations\ShelfBuilder;
use App\Enums\RecommendationEventType;
use App\Models\Genre;
use App\Models\Media;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function queriesFor(Closure $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();

    return count(DB::getQueryLog());
}

function candidateIds(User $user): array
{
    return app(ShelfBuilder::class)->forHome($user)->reject(fn ($shelf) => in_array($shelf->key, ['continue-reading', 'explore-by-subject']) || str_starts_with($shelf->key, 'collection-'))
        ->flatMap(fn ($shelf) => $shelf->items->pluck('id'))->unique()->values()->all();
}

test('the shelves are kept ten minutes: a second view builds and logs nothing', function () {
    [$user] = richReader();
    $builder = app(ShelfBuilder::class);

    $cold = queriesFor(fn () => $builder->forHome($user));
    $rows = Recommendation::count();
    $warm = queriesFor(fn () => $builder->forHome($user));

    expect($rows)->toBeGreaterThan(0)->and(Recommendation::count())->toBe($rows)
        ->and($warm)->toBeLessThan($cold / 3);
    fwrite(STDERR, "\nforHome cold: {$cold} queries, warm: {$warm}\n");

    $this->travel(11)->minutes();
    $builder->forHome($user);

    // Exploration is random, so the second fill may show a few titles more or fewer than the first: it logs again either way.
    expect(Recommendation::count())->toBeGreaterThan($rows + intdiv($rows, 2));
});

test('the cached ids carry the recommendation id and label of each tile', function () {
    [$user] = richReader();
    app(ShelfBuilder::class)->forHome($user);

    $tile = app(ShelfBuilder::class)->forHome($user)->firstWhere('key', 'because-you-read')->items->first();

    expect($tile->recommendation_id)->toBe(Recommendation::where('user_id', $user->id)->where('shelf', 'because-you-read')->where('media_id', $tile->id)->value('id'))
        ->and($tile->reason_label)->toStartWith('Because you read ');
});

test('a library change clears the shelves', function () {
    [$user, $catalog] = richReader();
    $builder = app(ShelfBuilder::class);
    $builder->forHome($user);
    $rows = Recommendation::count();

    app(AddToLibrary::class)->handle($user, $catalog[39]);

    expect(Cache::has('home:shelves:'.$user->id.':gated'))->toBeFalse();
    $builder->forHome($user);
    expect(Recommendation::count())->toBeGreaterThan($rows);
});

test('a rating clears the shelves', function () {
    [$user, $catalog] = richReader();
    app(ShelfBuilder::class)->forHome($user);

    app(RateMedia::class)->handle($user, $catalog[30], 9);

    expect(Cache::has('home:shelves:'.$user->id.':gated'))->toBeFalse();
});

test('a dismissal clears the shelves and the story is gone on the next view', function () {
    [$user] = richReader();
    $before = candidateIds($user);
    $recommendation = Recommendation::where('user_id', $user->id)->where('shelf', 'recommended')->firstOrFail();

    app(RecommendationLogger::class)->event($user, $recommendation->id, RecommendationEventType::Dismiss);

    expect(Cache::has('home:shelves:'.$user->id.':gated'))->toBeFalse()
        ->and($before)->toContain($recommendation->media_id)
        ->and(candidateIds($user))->not->toContain($recommendation->media_id);
});

test('turning mature titles off never serves the shelves built with them', function () {
    [$user, $catalog] = richReader();
    $mature = matureStory('Dark Romance');
    $mature->update(['published_on' => now()->subDay(), 'popularity_score' => 1, 'ratings_count' => 900]);
    DB::statement('refresh materialized view media_popularity');
    $user->profile->update(['show_mature' => true, 'adult_confirmed_at' => now()]);
    $user = $user->fresh();

    expect(candidateIds($user))->toContain($mature->id);

    // Written without the model, so nothing is told: the cache key alone must keep the old shelves from being served.
    DB::table('profiles')->where('user_id', $user->id)->update(['show_mature' => false, 'adult_confirmed_at' => null]);
    $user = User::find($user->id);

    expect(candidateIds($user))->not->toContain($mature->id);
});

test('saving the mature access preference forgets the cached shelves', function () {
    [$user] = richReader();
    app(ShelfBuilder::class)->forHome($user);
    expect(Cache::has('home:shelves:'.$user->id.':gated'))->toBeTrue();

    $user->profile->update(['show_mature' => true, 'adult_confirmed_at' => now()]);

    expect(Cache::has('home:shelves:'.$user->id.':gated'))->toBeFalse();
    app(ShelfBuilder::class)->forHome($user->fresh());
    expect(Cache::has('home:shelves:'.$user->id.':mature'))->toBeTrue();

    $user->profile->update(['show_mature' => false, 'adult_confirmed_at' => null]);

    expect(Cache::has('home:shelves:'.$user->id.':mature'))->toBeFalse();
});

test('opting out of personalization forgets the personalized shelves', function () {
    [$user] = richReader();
    expect(app(ShelfBuilder::class)->forHome($user)->pluck('key')->all())->toContain('recommended');

    $user->profile->update(['personalization_enabled' => false]);

    expect(app(ShelfBuilder::class)->forHome($user->fresh())->pluck('key')->all())->not->toContain('recommended');
});

test('a story that became gated or was deleted while cached drops out of the cached shelf', function () {
    [$user] = richReader();
    $before = candidateIds($user);
    $victim = Media::find($before[0]);
    $victim->delete();

    expect(candidateIds($user))->not->toContain($victim->id);
});

test('a story that became gated while cached is dropped for a gated reader and kept for one with access', function () {
    [$user, $catalog] = richReader();
    $before = candidateIds($user);
    $victim = Media::find($before[0]);
    $open = User::factory()->create();
    $open->profile->update(['show_mature' => true, 'adult_confirmed_at' => now()]);

    // A genre attached to the story tells the cache nothing: only the load through the mature gate can keep it out.
    $victim->genres()->attach(Genre::factory()->mature()->create());

    expect(Cache::has('home:shelves:'.$user->id.':gated'))->toBeTrue()
        ->and(candidateIds($user))->not->toContain($victim->id)
        ->and(Media::query()->visibleTo($open->fresh())->whereKey($victim->id)->exists())->toBeTrue();
});

test('a shelf title or tile label that names a title the reader can no longer see is dropped from the cache', function () {
    [$user, $catalog] = richReader();
    app(ShelfBuilder::class)->forHome($user);
    $before = app(ShelfBuilder::class)->forHome($user);
    expect($before->pluck('key')->all())->toContain('because-you-read', 'similar-to')
        ->and($before->firstWhere('key', 'similar-to')->items->first()->reason_label)->toBe('Similar themes to '.$catalog[7]->title);

    // The seed of "Because You Read" (the latest completed) and of "Similar To" (the latest favorite) turn mature while the shelves are cached.
    $mature = Genre::factory()->mature()->create();
    $catalog[0]->genres()->attach($mature);
    $catalog[7]->genres()->attach($mature);

    $after = app(ShelfBuilder::class)->forHome($user);
    $text = json_encode($after->map(fn ($shelf) => [$shelf->title, $shelf->items->pluck('reason_label')]));

    expect(Cache::has('home:shelves:'.$user->id.':gated'))->toBeTrue()
        ->and($after->pluck('key')->all())->not->toContain('because-you-read', 'similar-to')
        ->and($text)->not->toContain($catalog[0]->title)->and($text)->not->toContain($catalog[7]->title);
});

test('creating the profile row with mature access on forgets the cached shelves', function () {
    [$user] = richReader();
    // A reader whose profile row does not exist yet is served by the column defaults; saving the first row is a change of preferences too.
    DB::table('profiles')->where('user_id', $user->id)->delete();
    $user = User::find($user->id);
    app(ShelfBuilder::class)->forHome($user);
    expect(Cache::has('home:shelves:'.$user->id.':gated'))->toBeTrue();

    $user->profile()->create(['show_mature' => true, 'adult_confirmed_at' => now()]);

    expect(Cache::has('home:shelves:'.$user->id.':gated'))->toBeFalse();
});
