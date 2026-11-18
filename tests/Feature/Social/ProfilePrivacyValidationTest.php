<?php

use App\Enums\Visibility;
use App\Models\User;
use Livewire\Livewire;

test('a profile can\'t be made public without a handle', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::settings.privacy')
        ->set('profileVisibility', 'public')
        ->call('updatePrivacy')
        ->assertHasErrors(['profileVisibility'])
        ->assertSee('Choose a handle on your profile first.');

    expect($user->fresh()->profile->profile_visibility)->toBe(Visibility::Private);
});

test('a profile with a handle can be made public', function () {
    $this->actingAs($user = User::factory()->create());
    $user->profile()->updateOrCreate([], ['handle' => 'a_reader']);

    Livewire::test('pages::settings.privacy')
        ->set('profileVisibility', 'public')
        ->set('libraryVisibility', 'public')
        ->call('updatePrivacy')
        ->assertHasNoErrors();

    expect($user->fresh()->profile->profile_visibility)->toBe(Visibility::Public)
        ->and($user->fresh()->profile->library_visibility)->toBe(Visibility::Public);
});

test('a private profile needs no handle, and a library can be public on its own', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::settings.privacy')
        ->set('libraryVisibility', 'public')
        ->call('updatePrivacy')
        ->assertHasNoErrors();

    expect($user->fresh()->profile->library_visibility)->toBe(Visibility::Public);
});

test('a profile hidden by moderators can\'t be made public', function () {
    $this->actingAs($user = User::factory()->create());
    $user->profile()->updateOrCreate([], ['handle' => 'a_reader']);
    $user->profile()->update(['moderation_hidden_at' => now()]);

    Livewire::test('pages::settings.privacy')
        ->set('profileVisibility', 'public')
        ->call('updatePrivacy')
        ->assertHasErrors(['profileVisibility'])
        ->assertSee('Moderators have hidden your profile, so it stays private.');

    Livewire::test('pages::settings.privacy')
        ->set('libraryVisibility', 'public')
        ->call('updatePrivacy')
        ->assertHasErrors(['libraryVisibility']);

    $profile = $user->fresh()->profile;
    expect($profile->profile_visibility)->toBe(Visibility::Private)->and($profile->library_visibility)->toBe(Visibility::Private);
});

test('a hidden profile can still save private settings', function () {
    $this->actingAs($user = User::factory()->create());
    $user->profile()->updateOrCreate([], []);
    $user->profile()->update(['moderation_hidden_at' => now()]);

    Livewire::test('pages::settings.privacy')
        ->set('personalizationEnabled', false)
        ->call('updatePrivacy')
        ->assertHasNoErrors();

    expect($user->fresh()->personalizationEnabled())->toBeFalse();
});
