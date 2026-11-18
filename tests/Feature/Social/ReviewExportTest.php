<?php

use App\Enums\ReportReason;
use App\Jobs\ExportUserData;
use App\Models\Media;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

function exportedFile(User $user, string $name): array
{
    $path = Storage::disk('local')->allFiles("exports/{$user->id}")[0];
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($path));
    $raw = $zip->getFromName($name);
    $zip->close();

    return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
}

test('the export holds the reader’s own reviews with their status, not deleted ones or other readers’', function () {
    Storage::fake('local');
    Notification::fake();
    $user = User::factory()->create();
    $story = Media::factory()->create(['title' => 'Exported Tale', 'slug' => 'exported-tale']);
    Review::factory()->spoiler()->hidden()->create(['user_id' => $user->id, 'media_id' => $story->id, 'body' => 'Ünïcode body 📚']);
    Review::factory()->create(['user_id' => $user->id])->delete();
    Review::factory()->create(['body' => 'Somebody else']);

    (new ExportUserData($user->id))->handle();

    $reviews = exportedFile($user, 'reviews.json');

    expect($reviews)->toHaveCount(1)
        ->and($reviews[0])->toMatchArray(['title' => 'Exported Tale', 'slug' => 'exported-tale', 'body' => 'Ünïcode body 📚', 'has_spoilers' => true, 'status' => 'hidden'])
        ->and($reviews[0]['created_at'])->not->toBeNull();
});

test('the export lists the reader’s reports without naming the reported person or content', function () {
    Storage::fake('local');
    Notification::fake();
    $user = User::factory()->create();
    $target = Review::factory()->create(['body' => 'Reported words', 'user_id' => User::factory()->create(['name' => 'Reported Person', 'email' => 'reported@example.test'])]);
    Report::factory()->of($target)->create(['reporter_id' => $user->id, 'reason' => ReportReason::Abuse, 'details' => 'private details']);
    Report::factory()->create();

    (new ExportUserData($user->id))->handle();

    $reports = exportedFile($user, 'reports.json');
    $raw = json_encode($reports);

    expect($reports)->toHaveCount(1)
        ->and($reports[0])->toMatchArray(['target_type' => 'review', 'reason' => 'abuse', 'status' => 'open'])
        ->and($reports[0]['created_at'])->not->toBeNull()
        ->and($raw)->not->toContain('Reported')->and($raw)->not->toContain('reported@example.test');
});
