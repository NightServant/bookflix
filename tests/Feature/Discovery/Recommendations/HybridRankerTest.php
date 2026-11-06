<?php

use App\Discovery\Recommendations\Candidate;
use App\Discovery\Recommendations\HybridRanker;
use App\Discovery\Recommendations\RankingWeights;
use App\Enums\ModelKind;
use App\Enums\ReasonType;
use App\Models\Genre;
use App\Models\Media;
use App\Models\ModelVersion;
use App\Models\User;
use App\Models\UserInteraction;
use App\Models\UserPreference;
use Database\Seeders\DeweySeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

const FEATURE_KEYS = ['content_similarity', 'collaborative_score', 'dewey_similarity', 'popularity', 'freshness', 'personal_preference', 'context', 'novelty'];

function zeroWeights(): array
{
    return ['content' => 0.0, 'collaborative' => 0.0, 'dewey' => 0.0, 'popularity' => 0.0, 'freshness' => 0.0, 'preference' => 0.0, 'novelty' => 0.0, 'context' => 0.0];
}

function candidateFor(Media $media, float $score = 0.5, ReasonType $reason = ReasonType::Trending, string $source = 'trending', ?int $reasonMediaId = null, array $payload = []): Candidate
{
    return new Candidate($media->id, $source, $score, $reason, $reasonMediaId, $payload);
}

/** A reader with one completed seed, and six candidates that differ in every feature. */
function rankFixture(RankingWeights $weights): Collection
{
    $user = User::factory()->create();
    $seed = Media::factory()->create();
    libraryEntry($user, $seed, 'completed');
    $lsa = activeVersion('lsa');
    $cf = activeVersion('item-cf');
    $candidates = Media::factory()->count(6)->sequence(
        ['popularity_score' => 0.9, 'published_on' => now()->subDays(2)],
        ['popularity_score' => 0.1, 'published_on' => now()->subYears(5)],
        ['popularity_score' => 0.5, 'published_on' => null],
        ['popularity_score' => 0.0, 'published_on' => now()->subMonths(2)],
        ['popularity_score' => 0.7, 'published_on' => now()->subYear()],
        ['popularity_score' => 0.3, 'published_on' => now()->subDays(30)],
    )->create();
    foreach ($candidates as $i => $m) {
        similar($seed, $m, 'lsa', 0.9 - $i * 0.15, $lsa);
        $i % 2 === 0 && similar($seed, $m, 'item_cf', 0.8 - $i * 0.1, $cf);
    }

    return app(HybridRanker::class)->rank($user, $candidates->map(fn ($m) => candidateFor($m)), $weights);
}

/** The ids of rankFixture are not exposed, so single feature tests build their own. */
function featuresOf(User $user, array $candidates): Collection
{
    return app(HybridRanker::class)->rank($user, collect($candidates), RankingWeights::active())->keyBy('mediaId')->map->features;
}

test('score is the weighted sum of normalized features and stays within 0 and 1', function () {
    $ranked = rankFixture(RankingWeights::fromArray(config('bookflix.hybrid_weights')));

    expect($ranked)->toHaveCount(6)
        ->and($ranked->every(fn ($r) => $r->score >= 0 && $r->score <= 1))->toBeTrue()
        ->and($ranked->every(fn ($r) => collect($r->features)->every(fn ($f) => is_float($f) && $f >= 0 && $f <= 1)))->toBeTrue()
        ->and(array_keys($ranked->first()->features))->toBe(FEATURE_KEYS);

    $w = config('bookflix.hybrid_weights');
    $top = $ranked->first();
    $f = $top->features;
    $expected = $w['content'] * $f['content_similarity'] + $w['collaborative'] * $f['collaborative_score'] + $w['dewey'] * $f['dewey_similarity']
        + $w['popularity'] * $f['popularity'] + $w['freshness'] * $f['freshness'] + $w['preference'] * $f['personal_preference'] + $w['novelty'] * $f['novelty'];
    expect($top->score)->toEqualWithDelta($expected, 1e-9)
        ->and($ranked->pluck('score')->all())->toBe($ranked->pluck('score')->sortDesc()->values()->all());
});

test('changing a weight changes the order', function () {
    $contentHeavy = rankFixture(RankingWeights::fromArray(['content' => 1.0] + zeroWeights()));
    $noveltyHeavy = rankFixture(RankingWeights::fromArray(['novelty' => 1.0] + zeroWeights()));

    // Ids differ between the two fixtures, so compare the position each variant gives the strongest content match.
    expect($contentHeavy->first()->features['content_similarity'])->toBeGreaterThan($noveltyHeavy->first()->features['content_similarity'])
        ->and($noveltyHeavy->first()->features['novelty'])->toBeGreaterThan($contentHeavy->first()->features['novelty']);
});

test('a missing feature counts as zero', function () {
    $user = User::factory()->create();
    $m = Media::factory()->create(['published_on' => null, 'popularity_score' => 0]);

    $r = app(HybridRanker::class)->rank($user, collect([new Candidate($m->id, 'trending', 0.5, ReasonType::Trending)]), RankingWeights::active())->first();

    expect($r->features['content_similarity'])->toBe(0.0)
        ->and($r->features['collaborative_score'])->toBe(0.0)
        ->and($r->features['dewey_similarity'])->toBe(0.0)
        ->and($r->features['freshness'])->toBe(0.0)
        ->and($r->features['personal_preference'])->toBe(0.0)
        ->and($r->features['context'])->toBe(0.0)
        ->and(is_finite($r->score))->toBeTrue()->and($r->score)->toBeBetween(0.0, 1.0);
});

test('degenerate inputs rank without NaN and with scores in 0 to 1', function (array $weights) {
    $user = User::factory()->create();
    $media = Media::factory()->count(3)->create(['popularity_score' => 0, 'published_on' => null, 'ratings_count' => 0, 'average_rating' => null]);

    $one = app(HybridRanker::class)->rank($user, collect([candidateFor($media[0])]), RankingWeights::fromArray($weights));
    $all = app(HybridRanker::class)->rank($user, $media->map(fn ($m) => candidateFor($m, 0.0)), RankingWeights::fromArray($weights));

    expect($one)->toHaveCount(1)->and($all)->toHaveCount(3)
        ->and($one->concat($all)->every(fn ($r) => is_finite($r->score) && $r->score >= 0 && $r->score <= 1
            && collect($r->features)->every(fn ($f) => is_finite($f) && $f >= 0 && $f <= 1)))->toBeTrue()
        ->and($all->pluck('mediaId')->all())->toBe($media->pluck('id')->sort()->values()->all());
})->with([
    'config weights' => [fn () => config('bookflix.hybrid_weights')],
    'all zero weights' => [fn () => zeroWeights()],
    'weights summing above one' => [fn () => array_map(fn () => 1.0, zeroWeights())],
]);

test('a reader with no seeds and no preferences still ranks, by popularity and freshness alone', function () {
    $user = User::factory()->create();
    [$popular, $quiet] = Media::factory()->count(2)->sequence(['popularity_score' => 0.9], ['popularity_score' => 0.1])->create(['published_on' => null]);

    $ranked = app(HybridRanker::class)->rank($user, collect([candidateFor($quiet), candidateFor($popular)]), RankingWeights::fromArray(['popularity' => 1.0] + zeroWeights()));

    expect($ranked->pluck('mediaId')->all())->toBe([$popular->id, $quiet->id]);
});

test('freshness decays with age', function () {
    // `published_on` is a date, so "yesterday" is a day and a part old by evening: the clock is put early in the day to keep the test steady.
    $this->travelTo(today()->setTime(1, 0));
    $user = User::factory()->create();
    [$new, $old, $future] = Media::factory()->count(3)->sequence(['published_on' => now()->subDay()], ['published_on' => now()->subYears(3)], ['published_on' => now()->addMonths(2)])->create();

    $f = featuresOf($user, [candidateFor($new), candidateFor($old), candidateFor($future)]);

    expect($f[$new->id]['freshness'])->toBeGreaterThan(0.99)
        ->and($f[$old->id]['freshness'])->toBeLessThan(0.05)
        ->and($f[$future->id]['freshness'])->toBe(1.0);
});

test('popularity is the provider score plus the trending share, capped at one, and novelty is its complement', function () {
    $user = User::factory()->create();
    [$plain, $maxed] = Media::factory()->count(2)->sequence(['popularity_score' => 0.9], ['popularity_score' => 1.0])->create();
    config(['bookflix.recommendations.trending_weight' => 0.05]);

    $f = featuresOf($user, [candidateFor($plain), candidateFor($maxed)]);

    expect($f[$plain->id]['popularity'])->toEqualWithDelta(0.9, 1e-9)
        ->and($f[$plain->id]['novelty'])->toEqualWithDelta(0.1, 1e-9)
        ->and($f[$maxed->id]['popularity'])->toBe(1.0)
        ->and($f[$maxed->id]['novelty'])->toBe(0.0);
});

test('popularity counts Bookflix activity on top of the provider score', function () {
    $user = User::factory()->create();
    $busy = Media::factory()->create(['popularity_score' => 0.4]);
    $other = Media::factory()->create(['popularity_score' => 0.4]);
    UserInteraction::factory()->create(['user_id' => User::factory()->create()->id, 'media_id' => $busy->id, 'occurred_at' => now()]);
    DB::statement('refresh materialized view media_popularity');

    $f = featuresOf($user, [candidateFor($busy), candidateFor($other)]);

    expect($f[$busy->id]['popularity'])->toBeGreaterThan($f[$other->id]['popularity'])
        ->and($f[$other->id]['popularity'])->toEqualWithDelta(0.4, 1e-9);
});

test('content is the best neighbor score times the seed strength, collaborative the strength weighted mean', function () {
    $user = User::factory()->create();
    [$loved, $reading, $c] = Media::factory()->count(3)->create();
    libraryEntry($user, $loved, 'completed', favorite: true);   // 1.0
    libraryEntry($user, $reading, 'reading');                   // 0.5
    $lsa = activeVersion('lsa');
    $cf = activeVersion('item-cf');
    similar($loved, $c, 'lsa', 0.6, $lsa);
    similar($reading, $c, 'lsa', 0.9, $lsa);
    similar($loved, $c, 'item_cf', 0.8, $cf);
    similar($reading, $c, 'item_cf', 0.2, $cf);

    $f = featuresOf($user, [candidateFor($c)])[$c->id];

    expect($f['content_similarity'])->toEqualWithDelta(0.6, 1e-9)                                  // max(0.6 × 1.0, 0.9 × 0.5)
        ->and($f['collaborative_score'])->toEqualWithDelta((0.8 * 1.0 + 0.2 * 0.5) / 1.5, 1e-9);
});

test('neighbors from a candidate version or another reader are not read', function () {
    $user = User::factory()->create();
    [$seed, $c] = Media::factory()->count(2)->create();
    libraryEntry($user, $seed, 'completed');
    activeVersion('lsa');
    $candidateVersion = ModelVersion::factory()->create(['name' => 'lsa', 'kind' => ModelKind::Lsa]);
    similar($seed, $c, 'lsa', 0.9, $candidateVersion);

    expect(featuresOf($user, [candidateFor($c)])[$c->id]['content_similarity'])->toBe(0.0);
});

test('dewey similarity uses the deepest shared ancestor', function () {
    $this->seed(DeweySeeder::class);
    $user = User::factory()->create();
    [$seed, $near, $far, $none] = Media::factory()->count(4)->create();
    classified($seed, '813');
    classified($near, '811');
    classified($far, '510');
    libraryEntry($user, $seed, 'completed');

    $f = featuresOf($user, [candidateFor($near), candidateFor($far), candidateFor($none)]);

    expect($f[$near->id]['dewey_similarity'])->toEqualWithDelta(2 / 3, 1e-9)
        ->and($f[$far->id]['dewey_similarity'])->toBe(0.0)
        ->and($f[$none->id]['dewey_similarity'])->toBe(0.0);
});

test('preference is the affinity of genres and types, relative to the best candidate', function () {
    $user = User::factory()->create();
    [$fantasy, $romance] = Genre::factory()->count(2)->create();
    [$best, $half, $none] = Media::factory()->count(3)->create();
    $best->genres()->attach($fantasy);
    $half->genres()->attach($romance);
    UserPreference::create(['user_id' => $user->id, 'preferable_type' => 'genre', 'preferable_id' => $fantasy->id, 'weight' => 4]);
    UserPreference::create(['user_id' => $user->id, 'preferable_type' => 'genre', 'preferable_id' => $romance->id, 'weight' => 2]);

    $f = featuresOf($user, [candidateFor($best), candidateFor($half), candidateFor($none)]);

    expect($f[$best->id]['personal_preference'])->toBe(1.0)
        ->and($f[$half->id]['personal_preference'])->toEqualWithDelta(0.5, 1e-9)
        ->and($f[$none->id]['personal_preference'])->toBe(0.0);
});

test('genres of the seeds count towards preference by seed strength', function () {
    $user = User::factory()->create();
    [$genre, $other] = Genre::factory()->count(2)->create();
    [$seed, $likeSeed, $unlike] = Media::factory()->count(3)->create();
    $seed->genres()->attach($genre);
    $likeSeed->genres()->attach($genre);
    $unlike->genres()->attach($other);
    libraryEntry($user, $seed, 'completed');

    $f = featuresOf($user, [candidateFor($likeSeed), candidateFor($unlike)]);

    expect($f[$likeSeed->id]['personal_preference'])->toBe(1.0)->and($f[$unlike->id]['personal_preference'])->toBe(0.0);
});

test('duplicate candidates merge and the explanation comes from the highest scoring source, ties staying stable', function () {
    $user = User::factory()->create();
    $reason = Media::factory()->create(['title' => 'Dune']);
    [$a, $b] = Media::factory()->count(2)->create();

    $ranked = app(HybridRanker::class)->rank($user, collect([
        candidateFor($a, 0.2),
        new Candidate($a->id, 'content_similar', 0.9, ReasonType::BecauseYouRead, $reason->id),
        candidateFor($b, 0.5),
    ]), RankingWeights::fromArray(zeroWeights()));

    expect($ranked)->toHaveCount(2)
        ->and($ranked->pluck('mediaId')->all())->toBe([$a->id, $b->id])      // equal scores order by media id
        ->and($ranked->first()->explanation->type)->toBe(ReasonType::BecauseYouRead)
        ->and($ranked->first()->explanation->reasonTitle)->toBe('Dune')
        ->and($ranked->last()->explanation->type)->toBe(ReasonType::Trending);
});

test('weights fall back to config when no version exists and mirror an active version when present', function () {
    ModelVersion::query()->delete();
    expect(RankingWeights::active()->content)->toBe(0.30);

    ModelVersion::factory()->create(['name' => 'hybrid-weights', 'kind' => ModelKind::HybridWeights, 'status' => 'candidate', 'parameters' => ['content' => 0.9] + zeroWeights()]);
    expect(RankingWeights::active()->content)->toBe(0.30);

    ModelVersion::factory()->create(['name' => 'hybrid-weights', 'kind' => ModelKind::HybridWeights, 'status' => 'active', 'parameters' => ['content' => 0.5] + zeroWeights()]);
    expect(RankingWeights::active()->content)->toBe(0.5);
});

test('an empty candidate set returns empty', function () {
    expect(app(HybridRanker::class)->rank(User::factory()->create(), collect(), RankingWeights::active()))->toBeEmpty();
});

test('candidates that are gone, merged or gated for the reader are dropped', function () {
    $user = User::factory()->create();
    [$ok, $gone, $merged] = Media::factory()->count(3)->create();
    $gated = matureStory();
    $gone->delete();
    $merged->update(['merged_into_id' => $ok->id]);

    $ranked = app(HybridRanker::class)->rank($user, collect([$ok, $gone, $merged, $gated])->map(fn ($m) => candidateFor($m)), RankingWeights::active());

    expect($ranked->pluck('mediaId')->all())->toBe([$ok->id]);
});

test('ranking 100 candidates runs a constant number of queries', function () {
    $user = User::factory()->create();
    $seeds = Media::factory()->count(3)->create();
    $seeds->each(fn ($s) => libraryEntry($user, $s, 'completed'));
    $media = Media::factory()->count(100)->create();
    $lsa = activeVersion('lsa');
    $media->each(fn ($m, $i) => similar($seeds[$i % 3], $m, 'lsa', 0.5, $lsa));
    $candidates = $media->map(fn ($m) => candidateFor($m));
    $user->load('profile');

    DB::enableQueryLog();
    app(HybridRanker::class)->rank($user, $candidates, RankingWeights::active());
    $few = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($few)->toBeLessThanOrEqual(12);
});
