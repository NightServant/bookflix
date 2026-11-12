<?php

use App\Jobs\ExportLtrCandidates;
use App\Jobs\RunMlJob;
use App\Models\LtrCandidate;
use App\Models\Media;
use App\Models\User;
use App\Models\UserInteraction;
use Illuminate\Support\Facades\Bus;

function exportRun(): void
{
    app()->call([new ExportLtrCandidates, 'handle']);
}

/** An active reader with a seed title and `$count` visible neighbors of it. */
function readerWithNeighbors(int $count = 5, array $userState = []): array
{
    $user = User::factory()->create($userState);
    $seed = Media::factory()->create();
    libraryEntry($user, $seed, 'completed');
    UserInteraction::factory()->create(['user_id' => $user->id, 'media_id' => $seed->id, 'occurred_at' => now()->subDays(2)]);
    $version = activeVersion('lsa');
    $neighbors = Media::factory()->count($count)->create();
    foreach ($neighbors as $i => $neighbor) {
        similar($seed, $neighbor, 'lsa', 0.9 - $i * 0.05, $version);
    }

    return [$user, $seed, $neighbors];
}

beforeEach(fn () => Bus::fake([RunMlJob::class]));

test('without an active ltr version the export writes nothing and queues nothing', function () {
    readerWithNeighbors();

    exportRun();

    expect(LtrCandidate::count())->toBe(0);
    Bus::assertNothingDispatched();
});

test('an active reader gets their hybrid candidates with the features, and the scoring pipeline is queued', function () {
    [$user, , $neighbors] = readerWithNeighbors();
    activeVersion('ltr');

    exportRun();

    $rows = LtrCandidate::where('user_id', $user->id)->get();
    expect($rows->pluck('media_id')->all())->toContain(...$neighbors->pluck('id')->all())
        ->and(collect($rows->first()->features)->keys()->sort()->values()->all())->toBe(collect(['content_similarity', 'collaborative_score', 'dewey_similarity', 'popularity', 'freshness', 'personal_preference', 'context', 'novelty'])->sort()->values()->all())
        ->and($rows->first()->features['content_similarity'])->toBeGreaterThan(0);
    Bus::assertDispatched(RunMlJob::class, fn (RunMlJob $job) => $job->name === 'score-ltr' && $job->pipeline === 'ltr-scores');
});

test('a reader\'s old rows are replaced as a whole', function () {
    [$user, , $neighbors] = readerWithNeighbors(3);
    activeVersion('ltr');
    $gone = Media::factory()->create();
    LtrCandidate::create(['user_id' => $user->id, 'media_id' => $gone->id, 'features' => [], 'generated_at' => now()->subDay()]);
    $gone->delete();

    exportRun();
    exportRun();

    $ids = LtrCandidate::where('user_id', $user->id)->pluck('media_id')->all();
    expect($ids)->toContain(...$neighbors->pluck('id')->all())->not->toContain($gone->id)->and(count($ids))->toBe(count(array_unique($ids)));
});

test('deactivated, opted out and inactive readers are not exported', function () {
    activeVersion('ltr');
    [$deactivated] = readerWithNeighbors(2, ['deactivated_at' => now()]);
    [$optedOut] = readerWithNeighbors(2);
    $optedOut->profile->update(['personalization_enabled' => false]);
    [$quiet] = readerWithNeighbors(2);
    UserInteraction::where('user_id', $quiet->id)->update(['occurred_at' => now()->subDays(45)]);
    [$active] = readerWithNeighbors(2);

    exportRun();

    expect(LtrCandidate::pluck('user_id')->unique()->all())->toBe([$active->id]);
});

test('a work the reader may not see is never exported, and a reader who opted in to mature content gets it', function () {
    activeVersion('ltr');
    [$gated, $seed] = readerWithNeighbors(2);
    $mature = matureStory();
    $deleted = Media::factory()->create();
    $target = Media::factory()->create();
    $merged = Media::factory()->create(['merged_into_id' => $target->id]);
    $lsa = activeVersion('lsa');
    foreach ([$mature, $deleted, $merged] as $m) {
        similar($seed, $m, 'lsa', 0.99, $lsa);
    }
    $deleted->delete();
    $open = User::factory()->showsMature()->create();
    libraryEntry($open, $seed, 'completed');
    UserInteraction::factory()->create(['user_id' => $open->id, 'media_id' => $seed->id, 'occurred_at' => now()->subDay()]);

    exportRun();

    $forGated = LtrCandidate::where('user_id', $gated->id)->pluck('media_id')->all();
    $forOpen = LtrCandidate::where('user_id', $open->id)->pluck('media_id')->all();
    expect($forGated)->not->toContain($mature->id, $deleted->id, $merged->id)->and($forOpen)->toContain($mature->id)
        ->and($forOpen)->not->toContain($deleted->id, $merged->id);
});

test('at most 300 candidates are exported per reader', function () {
    expect(ExportLtrCandidates::LIMIT)->toBe(300);
});

test('rows nobody rewrote for two days are cleared', function () {
    activeVersion('ltr');
    $user = User::factory()->create();
    $media = Media::factory()->create();
    LtrCandidate::create(['user_id' => $user->id, 'media_id' => $media->id, 'features' => [], 'generated_at' => now()->subHours(49)]);

    exportRun();

    expect(LtrCandidate::count())->toBe(0);
    Bus::assertNothingDispatched();
});
