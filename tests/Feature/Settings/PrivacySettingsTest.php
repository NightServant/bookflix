<?php

use App\Enums\Visibility;
use App\Models\User;
use Livewire\Livewire;

test('privacy page is displayed', function () {
    $this->actingAs(User::factory()->create())->get(route('privacy.edit'))->assertOk();
});

test('privacy defaults are private with personalization on', function () {
    $user = User::factory()->create();

    expect($user->profile->profile_visibility)->toBe(Visibility::Private)
        ->and($user->profile->library_visibility)->toBe(Visibility::Private)
        ->and($user->personalizationEnabled())->toBeTrue();
});

test('privacy settings can be updated', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::settings.privacy')
        ->set('profileVisibility', 'public')
        ->set('libraryVisibility', 'private')
        ->set('personalizationEnabled', false)
        ->call('updatePrivacy')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->profile->profile_visibility)->toBe(Visibility::Public)
        ->and($user->personalizationEnabled())->toBeFalse();
});

test('visibility must be private or public', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.privacy')
        ->set('profileVisibility', 'friends')
        ->call('updatePrivacy')
        ->assertHasErrors(['profileVisibility']);
});
