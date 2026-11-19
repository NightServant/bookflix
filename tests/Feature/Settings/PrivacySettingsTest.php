<?php

use App\Access\MatureAccess;
use App\Models\User;
use Livewire\Livewire;

test('privacy page is displayed', function () {
    $this->actingAs(User::factory()->create())->get(route('privacy.edit'))->assertOk();
});

test('personalization is on by default', function () {
    expect(User::factory()->create()->personalizationEnabled())->toBeTrue();
});

test('the page offers no visibility choice: profiles, libraries and collections are private', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.privacy')->assertDontSee('Profile visibility')->assertDontSee('Library visibility')
        ->assertSee('private')->assertSee('Personalization');
});

test('personalization can be turned off from the privacy settings', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::settings.privacy')
        ->set('personalizationEnabled', false)
        ->call('updatePrivacy')
        ->assertHasNoErrors();

    expect($user->refresh()->personalizationEnabled())->toBeFalse();
});

test('mature and adult titles are off by default and a guest is always gated', function () {
    $user = User::factory()->create();

    expect($user->profile->show_mature)->toBeFalse()
        ->and(MatureAccess::allows($user))->toBeFalse()
        ->and(MatureAccess::allows(null))->toBeFalse();
});

test('turning mature titles on needs the 18 or older confirmation', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::settings.privacy')
        ->set('showMature', true)
        ->call('updateMature')
        ->assertHasErrors(['confirmAdult']);

    expect(MatureAccess::allows($user->refresh()))->toBeFalse()
        ->and($user->profile->adult_confirmed_at)->toBeNull();
});

test('a confirmed reader sees mature and adult titles, and turning them off forgets the confirmation', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::settings.privacy')
        ->set('showMature', true)
        ->set('confirmAdult', true)
        ->call('updateMature')
        ->assertHasNoErrors();

    $user->refresh();
    expect(MatureAccess::allows($user))->toBeTrue()->and($user->profile->adult_confirmed_at)->not->toBeNull();

    Livewire::test('pages::settings.privacy')
        ->assertSet('showMature', true)
        ->assertSet('confirmAdult', true)
        ->set('showMature', false)
        ->call('updateMature')
        ->assertHasNoErrors();

    $user->refresh();
    expect(MatureAccess::allows($user))->toBeFalse()->and($user->profile->adult_confirmed_at)->toBeNull();

    Livewire::test('pages::settings.privacy')
        ->set('showMature', true)
        ->call('updateMature')
        ->assertHasErrors(['confirmAdult']);
});

test('the switch alone is not access: a profile without the confirmation stays gated', function () {
    $user = User::factory()->create();
    $user->profile()->updateOrCreate([], ['show_mature' => true]);

    expect(MatureAccess::allows($user->refresh()))->toBeFalse();
});

test('the privacy page offers the mature titles setting with the confirmation', function () {
    $html = $this->actingAs(User::factory()->create())->get(route('privacy.edit'))->assertOk()->getContent();

    expect($html)->toContain('Show mature and adult titles')->toContain('I confirm that I am 18 years of age or older.');
});
