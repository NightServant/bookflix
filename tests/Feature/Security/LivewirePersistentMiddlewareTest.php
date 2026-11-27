<?php

use App\Http\Middleware\EnsureOnboarded;
use App\Http\Middleware\EnsureReaderAccount;
use App\Models\User;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RequirePassword;
use Livewire\Livewire;

/** The first component snapshot a page rendered for $user, as the browser would hold it. */
$snapshotOf = function (User $user, string $routeName): string {
    $html = test()->actingAs($user)->get(route($routeName))->assertOk()->getContent();

    preg_match('/wire:snapshot="([^"]+)"/', $html, $match);

    return html_entity_decode($match[1], ENT_QUOTES);
};

/** What the browser posts to Livewire's update endpoint for a bare refresh of one snapshot. */
$replay = fn (User $user, string $snapshot) => test()->actingAs($user)->postJson(
    Livewire::getUpdateUri(),
    ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['method' => '$refresh', 'params' => [], 'metadata' => []]]]]],
    ['X-Livewire' => '1'],
);

test('livewire updates re-run the middleware their page route ran', function (string $middleware) {
    expect(Livewire::getPersistentMiddleware())->toContain($middleware);
})->with([EnsureEmailIsVerified::class, EnsureReaderAccount::class, EnsureOnboarded::class, RequirePassword::class]);

test('the replay helper works for the user who owns the snapshot', function () use ($snapshotOf, $replay) {
    $reader = User::factory()->create();

    $replay($reader, $snapshotOf($reader, 'library.index'))->assertOk();
});

test('an unverified reader replaying a verified reader snapshot is refused by the verified middleware', function () use ($snapshotOf, $replay) {
    $snapshot = $snapshotOf(User::factory()->create(), 'library.index');

    $replay(User::factory()->unverified()->create(), $snapshot)->assertForbidden();
});

test('an admin replaying a reader page snapshot is refused', function () use ($snapshotOf, $replay) {
    $snapshot = $snapshotOf(User::factory()->create(), 'library.index');

    $replay(User::factory()->admin()->create(), $snapshot)->assertForbidden();
});
