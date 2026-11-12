<?php

use App\Console\Commands\LtrReadiness;
use App\Jobs\RunMlJob;
use App\Models\Media;
use App\Models\Recommendation;
use App\Models\RecommendationEvent;
use App\Models\User;

function loggedFor(User $user, Media $media, array $events, int $ageDays = 20, ?int $position = 0): void
{
    $row = Recommendation::factory()->create(['user_id' => $user->id, 'media_id' => $media->id, 'shelf' => 'recommended', 'position' => $position, 'generated_at' => now()->subDays($ageDays)]);

    foreach ($events as $event) {
        RecommendationEvent::factory()->create(['recommendation_id' => $row->id, 'event' => $event, 'occurred_at' => $row->generated_at->addMinute()]);
    }
}

/** The fixture of ml/tests/test_impressions_db.py `readiness_fixture`: both count four labeled impressions. */
function readinessFixture(): void
{
    $media = Media::factory()->count(6)->create();
    $active = User::factory()->create();
    $deactivated = User::factory()->create(['deactivated_at' => now()]);
    $optedOut = User::factory()->create();
    $optedOut->profile->update(['personalization_enabled' => false]);

    loggedFor($active, $media[0], ['impression', 'impression']);
    loggedFor($active, $media[1], ['impression', 'click']);
    loggedFor($active, $media[2], ['impression'], ageDays: 89);
    loggedFor($active, $media[3], ['impression'], position: null);
    loggedFor($active, $media[4], ['impression'], ageDays: 3);
    loggedFor($active, $media[5], []);
    loggedFor($deactivated, $media[0], ['impression']);
    loggedFor($optedOut, $media[0], ['impression']);
    loggedFor($active, $media[5], ['impression'], ageDays: 100);
    loggedFor($active, $media[3], ['impression'], ageDays: 30);
}

test('the readiness count is the number the Python export counts on the shared fixture', function () {
    readinessFixture();

    expect(LtrReadiness::count())->toBe(4);
});

test('a work that is gone does not count', function () {
    $user = User::factory()->create();
    [$live, $deleted, $merged] = Media::factory()->count(3)->create();
    $merged->update(['merged_into_id' => $live->id]);
    $deleted->delete();
    foreach ([$live, $deleted, $merged] as $media) {
        loggedFor($user, $media, ['impression']);
    }

    expect(LtrReadiness::count())->toBe(1);
});

test('the command prints the count against the gate', function () {
    readinessFixture();

    $this->artisan('bookflix:ltr-readiness')->expectsOutput('Labeled impressions: 4 of 50,000 needed (not enough yet).')->assertSuccessful();

    expect(RunMlJob::LTR_MIN_LABELED)->toBe(50000);
});
