<?php

use App\Http\Middleware\EnsureOnboarded;
use App\Http\Middleware\EnsureReaderAccount;
use App\Http\Middleware\RequirePassword;
use App\Models\User;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Livewire\Livewire;

/** The snapshot of the page component $component as $user's browser holds it (not the layout's own components). */
$snapshotOf = function (User $user, string $routeName, string $component, array $session = []): string {
    $html = test()->actingAs($user)->withSession($session)->get(route($routeName))->assertOk()->getContent();

    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
            return $snapshot;
        }
    }

    throw new RuntimeException("No snapshot of {$component} on {$routeName}.");
};

/** What the browser posts to Livewire's update endpoint for a call on one snapshot; $headers shape the client (JSON or a browser). */
$call = function (User $user, string $snapshot, string $method, array $headers, array $session = []) {
    $payload = ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['method' => $method, 'params' => [], 'metadata' => []]]]]];

    return test()->actingAs($user)->withSession($session)->call(
        'POST',
        Livewire::getUpdateUri(),
        server: test()->transformHeadersToServerVars(['Content-Type' => 'application/json', 'X-Livewire' => '1', ...$headers]),
        content: json_encode($payload),
    );
};

$replay = fn (User $user, string $snapshot) => $call($user, $snapshot, '$refresh', ['Accept' => 'application/json']);

test('livewire updates re-run the middleware their page route ran', function (string $middleware) {
    expect(Livewire::getPersistentMiddleware())->toContain($middleware);
})->with([EnsureEmailIsVerified::class, EnsureReaderAccount::class, EnsureOnboarded::class, RequirePassword::class]);

test('the replay helper works for the user who owns the snapshot', function () use ($snapshotOf, $replay) {
    $reader = User::factory()->create();

    $replay($reader, $snapshotOf($reader, 'library.index', 'pages::library.index'))->assertOk();
});

test('an unverified reader replaying a verified reader snapshot is refused by the verified middleware', function () use ($snapshotOf, $replay) {
    $snapshot = $snapshotOf(User::factory()->create(), 'library.index', 'pages::library.index');

    $replay(User::factory()->unverified()->create(), $snapshot)->assertForbidden();
});

test('an admin replaying a reader page snapshot is refused', function () use ($snapshotOf, $replay) {
    $snapshot = $snapshotOf(User::factory()->create(), 'library.index', 'pages::library.index');

    $replay(User::factory()->admin()->create(), $snapshot)->assertForbidden();
});

test('a lapsed password confirmation stops a replayed security update, for a JSON client and for a browser', function (array $headers, int $status) use ($snapshotOf, $call) {
    $reader = User::factory()->withTwoFactor()->create();
    $snapshot = $snapshotOf($reader, 'security.edit', 'pages::settings.security', ['auth.password_confirmed_at' => time()]);

    $response = $call($reader, $snapshot, 'disable', $headers, ['auth.password_confirmed_at' => time() - 20000]);

    $response->assertStatus($status);
    if ($status === 302) {
        $response->assertRedirect(route('password.confirm'));
    }
    expect($reader->fresh()->two_factor_secret)->not->toBeNull();
})->with([
    'JSON client' => [['Accept' => 'application/json'], 423],
    'browser' => [['Accept' => 'text/html'], 302],
]);

test('a reader who has not onboarded replaying a Home snapshot is sent to onboarding', function () use ($snapshotOf, $call) {
    $reader = User::factory()->create();
    $snapshot = $snapshotOf($reader, 'home', 'pages::home.index');
    $reader->profile()->update(['onboarded_at' => null]);
    $reader->unsetRelation('profile');

    $call($reader, $snapshot, '$refresh', ['Accept' => 'text/html'])->assertRedirect(route('onboarding'));
});
