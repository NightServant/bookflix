<?php

use App\Actions\Social\FollowUser;
use App\Enums\Visibility;
use App\Jobs\ExportUserData;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

function exportedFollowing(User $user): array
{
    $path = Storage::disk('local')->allFiles("exports/{$user->id}")[0];
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($path));
    $following = json_decode($zip->getFromName('following.json'), true, flags: JSON_THROW_ON_ERROR);
    $zip->close();

    return $following;
}

function exportTarget(string $handle): User
{
    $user = User::factory()->create();
    $user->profile()->updateOrCreate([], ['handle' => $handle, 'profile_visibility' => Visibility::Public]);

    return $user->fresh();
}

test('the export lists the handles of followed readers who are still viewable', function () {
    Storage::fake('local');
    Notification::fake();

    $user = User::factory()->create();
    $kept = exportTarget('kept_one');
    $private = exportTarget('went_private');
    $gone = exportTarget('went_away');

    foreach ([$kept, $private, $gone] as $target) {
        app(FollowUser::class)->handle($user, $target);
    }

    $private->profile()->update(['profile_visibility' => Visibility::Private]);
    $gone->forceFill(['deactivated_at' => now()])->save();

    (new ExportUserData($user->id))->handle();

    $following = exportedFollowing($user);
    expect(array_column($following, 'handle'))->toBe(['kept_one'])
        ->and($following[0]['followed_at'])->not->toBeNull()
        ->and(Follow::query()->count())->toBe(3);
});

test('the export holds nothing about who follows the reader', function () {
    Storage::fake('local');
    Notification::fake();

    $user = exportTarget('the_exporter');
    app(FollowUser::class)->handle(exportTarget('a_follower'), $user);

    (new ExportUserData($user->id))->handle();

    expect(exportedFollowing($user))->toBe([]);
});
