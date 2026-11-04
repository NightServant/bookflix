<?php

use App\Actions\Library\AddToLibrary;
use App\Actions\Ratings\RateMedia;
use App\Discovery\Recommendations\RecommendationLogger;
use App\Discovery\Recommendations\ShelfBuilder;
use App\Enums\RecommendationEventType;
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

    expect(Recommendation::count())->toBe($rows * 2);
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
