<?php

use App\Discovery\Recommendations\Explanation;
use App\Discovery\Recommendations\LtrRanker;
use App\Discovery\Recommendations\RankedItem;
use App\Discovery\Recommendations\ShelfBuilder;
use App\Enums\ModelKind;
use App\Enums\ModelStatus;
use App\Enums\ReasonType;
use App\Models\LtrScore;
use App\Models\Media;
use App\Models\ModelVersion;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Random\Engine\Mt19937;
use Random\Randomizer;

function hybridOrder(Media ...$media): Collection
{
    return collect($media)->values()->map(fn (Media $m, int $i): RankedItem => new RankedItem(
        $m->id, 0.9 - $i * 0.1, ['popularity' => 0.5], new Explanation(ReasonType::Trending), $i === 0 ? 99 : null,
    ));
}

function ltrScore(User $user, Media $media, float $score, ModelVersion $version, $at = null): void
{
    LtrScore::updateOrCreate(['user_id' => $user->id, 'media_id' => $media->id], ['score' => $score, 'model_version_id' => $version->id, 'generated_at' => $at ?? now()]);
}

test('fresh scores reorder the items, normalised to the unit interval, and keep their explanations and features', function () {
    $user = User::factory()->create();
    [$a, $b, $c] = Media::factory()->count(3)->create();
    $version = activeVersion('ltr');
    ltrScore($user, $a, -2.0, $version);
    ltrScore($user, $b, 3.0, $version);
    ltrScore($user, $c, 0.5, $version);

    $result = app(LtrRanker::class)->rank($user, hybridOrder($a, $b, $c));

    expect($result->pluck('mediaId')->all())->toBe([$b->id, $c->id, $a->id])
        ->and($result->every(fn (RankedItem $i) => $i->score >= 0.0 && $i->score <= 1.0))->toBeTrue()
        ->and($result->first()->score)->toBe(1.0)
        ->and($result->last()->score)->toBe(0.5)
        ->and($result->firstWhere('mediaId', $a->id)->reasonMediaId)->toBe(99)
        ->and($result->first()->features)->toBe(['popularity' => 0.5])
        ->and($result->first()->explanation->type)->toBe(ReasonType::Trending);
});

test('unscored candidates follow the scored ones in their hybrid order', function () {
    $user = User::factory()->create();
    [$a, $b, $c, $d] = Media::factory()->count(4)->create();
    $version = activeVersion('ltr');
    ltrScore($user, $d, 1.0, $version);
    ltrScore($user, $c, 2.0, $version);

    $result = app(LtrRanker::class)->rank($user, hybridOrder($a, $b, $c, $d));

    expect($result->pluck('mediaId')->all())->toBe([$c->id, $d->id, $a->id, $b->id])
        ->and($result->slice(2)->every(fn (RankedItem $i) => $i->score < 0.5))->toBeTrue();
});

test('scores older than 36 hours give null, so the hybrid order is used', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    ltrScore($user, $media, 1.0, activeVersion('ltr'), now()->subHours(37));

    expect(app(LtrRanker::class)->rank($user, hybridOrder($media)))->toBeNull();

    LtrScore::query()->update(['generated_at' => now()->subHours(35)]);

    expect(app(LtrRanker::class)->rank($user, hybridOrder($media)))->not->toBeNull();
});

test('a reader with no rows, no active version, or only a retired version gets null', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();

    expect(app(LtrRanker::class)->rank($user, hybridOrder($media)))->toBeNull();

    $retired = ModelVersion::factory()->create(['name' => 'ltr', 'kind' => ModelKind::Ltr, 'status' => ModelStatus::Retired]);
    ltrScore($user, $media, 1.0, $retired);
    expect(app(LtrRanker::class)->rank($user, hybridOrder($media)))->toBeNull();

    $active = activeVersion('ltr');
    expect(app(LtrRanker::class)->rank($user, hybridOrder($media)))->toBeNull();

    ltrScore(User::factory()->create(), $media, 1.0, $active);
    expect(app(LtrRanker::class)->rank($user, hybridOrder($media)))->toBeNull();
});

test('scores of an older version are never read once another is active', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    $old = ModelVersion::factory()->create(['name' => 'ltr', 'kind' => ModelKind::Ltr, 'status' => ModelStatus::Retired]);
    ltrScore($user, $media, 1.0, $old);
    activeVersion('ltr');

    expect(app(LtrRanker::class)->rank($user, hybridOrder($media)))->toBeNull();
});

test('titles with scores that are not among the candidates are never added', function () {
    $user = User::factory()->create();
    [$a, $stranger] = Media::factory()->count(2)->create();
    $version = activeVersion('ltr');
    ltrScore($user, $a, 1.0, $version);
    ltrScore($user, $stranger, 9.0, $version);

    expect(app(LtrRanker::class)->rank($user, hybridOrder($a))->pluck('mediaId')->all())->toBe([$a->id]);
});

test('scores that are all equal give every scored title the top score', function () {
    $user = User::factory()->create();
    [$a, $b] = Media::factory()->count(2)->create();
    $version = activeVersion('ltr');
    ltrScore($user, $a, 0.3, $version);
    ltrScore($user, $b, 0.3, $version);

    expect(app(LtrRanker::class)->rank($user, hybridOrder($a, $b))->pluck('score')->all())->toBe([1.0, 1.0]);
});

function recommendedIds(User $user): array
{
    Cache::flush();
    app()->bind(Randomizer::class, fn () => new Randomizer(new Mt19937(4)));

    return app()->make(ShelfBuilder::class)->forHome($user)->firstWhere('key', 'recommended')->items->pluck('id')->all();
}

test('a reader with no scores, stale scores or a retired version gets exactly today\'s Recommended shelf', function () {
    [$user, $catalog] = richReader();
    $today = recommendedIds($user);
    $titles = $catalog->slice(10, 15)->values();

    // No active version, only rows.
    $retired = ModelVersion::factory()->create(['name' => 'ltr', 'kind' => ModelKind::Ltr, 'status' => ModelStatus::Retired]);
    foreach ($titles as $i => $media) {
        ltrScore($user, $media, $i, $retired);
    }
    expect(recommendedIds($user))->toBe($today);

    // Active version, no rows of it, then stale rows.
    $active = activeVersion('ltr');
    expect(recommendedIds($user))->toBe($today);
    foreach ($titles as $i => $media) {
        ltrScore($user, $media, $i, $active);
    }
    LtrScore::where('model_version_id', $active->id)->update(['generated_at' => now()->subHours(40)]);

    expect(recommendedIds($user))->toBe($today)
        ->and(Recommendation::where('model_version_id', $active->id)->count())->toBe(0);
});

test('Home renders with empty ltr tables and with an active version that has no scores', function () {
    [$user] = richReader();
    $this->actingAs($user);
    $this->get(route('home'))->assertOk();

    activeVersion('ltr');
    Cache::flush();

    $this->get(route('home'))->assertOk()->assertSee('Recommended For You');
});

test('with fresh scores the Recommended shelf follows them and its rows are logged under the ltr version', function () {
    [$user, $catalog] = richReader();
    $today = recommendedIds($user);
    $version = activeVersion('ltr');
    Recommendation::query()->delete();
    // Score the titles it shows today in the opposite order, and every other title below them.
    foreach (array_reverse($today) as $i => $id) {
        LtrScore::create(['user_id' => $user->id, 'media_id' => $id, 'score' => $i + 10, 'model_version_id' => $version->id, 'generated_at' => now()]);
    }

    $learned = recommendedIds($user);
    $hybridVersion = ModelVersion::activeFor('hybrid-weights');

    expect($learned)->not->toBe($today)->and(collect($learned)->intersect($today)->count())->toBeGreaterThan(10)
        ->and(Recommendation::where('shelf', 'recommended')->where('model_version_id', $version->id)->count())->toBe(count($learned))
        ->and(Recommendation::where('shelf', '!=', 'recommended')->where('model_version_id', $version->id)->count())->toBe(0)
        ->and(Recommendation::where('shelf', '!=', 'recommended')->where('model_version_id', $hybridVersion->id)->count())->toBeGreaterThan(0);
});
