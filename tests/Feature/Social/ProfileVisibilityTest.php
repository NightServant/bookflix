<?php

use App\Enums\Visibility;
use App\Models\Profile;
use App\Models\User;
use App\Social\ProfileLookup;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

/**
 * A reader with a handle and the given visibility.
 *
 * @param  array<string, mixed>  $profile
 * @param  array<string, mixed>  $user
 */
function publicReader(string $handle = 'ada_l', array $profile = [], array $user = []): User
{
    $reader = User::factory()->create($user);
    $reader->profile()->updateOrCreate([], ['handle' => $handle, 'profile_visibility' => Visibility::Public, ...Arr::except($profile, 'moderation_hidden_at')]);

    // Only moderators set this, so it is not mass assignable.
    if (isset($profile['moderation_hidden_at'])) {
        Profile::query()->where('user_id', $reader->id)->update(['moderation_hidden_at' => $profile['moderation_hidden_at']]);
    }

    return $reader->fresh();
}

function canSee(string $ability, ?User $viewer, User $owner): bool
{
    return Gate::forUser($viewer)->allows($ability, $owner->fresh());
}

test('a private profile is visible to its owner only', function () {
    $owner = User::factory()->create();

    expect(canSee('view-profile', $owner, $owner))->toBeTrue()
        ->and(canSee('view-profile', User::factory()->create(), $owner))->toBeFalse()
        ->and(canSee('view-profile', null, $owner))->toBeFalse();
});

test('a public profile is visible to other readers and to a guest at the gate', function () {
    $owner = publicReader();

    expect(canSee('view-profile', User::factory()->create(), $owner))->toBeTrue()
        ->and(canSee('view-profile', null, $owner))->toBeTrue();
});

test('an admin owner is never visible to others', function () {
    $owner = publicReader('the_boss', user: ['is_admin' => true]);

    expect(canSee('view-profile', User::factory()->create(), $owner))->toBeFalse()
        ->and(canSee('view-library', User::factory()->create(), $owner))->toBeFalse();
});

test('a deactivated owner is invisible even with public settings', function () {
    $owner = publicReader(profile: ['library_visibility' => Visibility::Public], user: ['deactivated_at' => now()]);

    expect(canSee('view-profile', User::factory()->create(), $owner))->toBeFalse()
        ->and(canSee('view-library', User::factory()->create(), $owner))->toBeFalse();
});

test('a profile hidden by moderators is invisible to others but not to its owner', function () {
    $owner = publicReader(profile: ['library_visibility' => Visibility::Public, 'moderation_hidden_at' => now()]);

    expect(canSee('view-profile', User::factory()->create(), $owner))->toBeFalse()
        ->and(canSee('view-library', User::factory()->create(), $owner))->toBeFalse()
        ->and(canSee('view-profile', $owner, $owner))->toBeTrue();
});

test('the library stays private while the profile is public', function () {
    $owner = publicReader();
    $reader = User::factory()->create();

    expect(canSee('view-profile', $reader, $owner))->toBeTrue()
        ->and(canSee('view-library', $reader, $owner))->toBeFalse()
        ->and(canSee('view-library', $owner, $owner))->toBeTrue();
});

test('a public library is visible only through a viewable profile', function () {
    $reader = User::factory()->create();
    $both = publicReader('both_pub', ['library_visibility' => Visibility::Public]);
    $libraryOnly = publicReader('lib_only', ['profile_visibility' => Visibility::Private, 'library_visibility' => Visibility::Public]);

    expect(canSee('view-library', $reader, $both))->toBeTrue()
        ->and(canSee('view-library', null, $both))->toBeTrue()
        ->and(canSee('view-library', $reader, $libraryOnly))->toBeFalse()
        ->and(canSee('view-library', $libraryOnly, $libraryOnly))->toBeTrue();
});

test('a visibility change takes effect immediately', function () {
    $owner = publicReader(profile: ['library_visibility' => Visibility::Public]);
    $reader = User::factory()->create();

    expect(canSee('view-library', $reader, $owner))->toBeTrue();

    $owner->profile()->update(['profile_visibility' => Visibility::Private]);
    expect(canSee('view-profile', $reader, $owner))->toBeFalse()
        ->and(canSee('view-library', $reader, $owner))->toBeFalse();

    $owner->profile()->update(['profile_visibility' => Visibility::Public, 'library_visibility' => Visibility::Private]);
    expect(canSee('view-profile', $reader, $owner))->toBeTrue()
        ->and(canSee('view-library', $reader, $owner))->toBeFalse();

    $owner->forceFill(['deactivated_at' => now()])->save();
    expect(canSee('view-profile', $reader, $owner))->toBeFalse();
});

test('the lookup resolves a handle case-insensitively to a viewable owner with the profile loaded', function () {
    $owner = publicReader('ada_l');
    $found = app(ProfileLookup::class)->find(User::factory()->create(), ' ADA_l ');

    expect($found?->is($owner))->toBeTrue()
        ->and($found->relationLoaded('profile'))->toBeTrue()
        ->and(app(ProfileLookup::class)->find(null, 'ada_l')?->is($owner))->toBeTrue();
});

test('the lookup returns null for unknown, retired, private, admin, deactivated and hidden handles alike', function () {
    $reader = User::factory()->create();
    $lookup = app(ProfileLookup::class);

    publicReader('is_private', ['profile_visibility' => Visibility::Private]);
    publicReader('an_admin', user: ['is_admin' => true]);
    publicReader('is_gone', user: ['deactivated_at' => now()]);
    publicReader('is_hidden', ['moderation_hidden_at' => now()]);
    $renamed = publicReader('old_name');
    $renamed->profile()->update(['handle' => 'new_name']);

    foreach (['nobody_here', 'is_private', 'an_admin', 'is_gone', 'is_hidden', 'old_name'] as $handle) {
        expect($lookup->find($reader, $handle))->toBeNull();
    }

    expect($lookup->find($reader, 'new_name'))->not->toBeNull();
});

test('the owner may look up their own private handle', function () {
    $owner = publicReader('mine_only', ['profile_visibility' => Visibility::Private]);

    expect(app(ProfileLookup::class)->find($owner, 'mine_only')?->is($owner))->toBeTrue();
});
