<?php

use App\Models\User;
use Livewire\Livewire;

test('input focus ring uses the electric accent, not red', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('ring-accent-electric ring-offset-2 ring-offset-canvas')
        ->not->toContain('ring-accent ');
});

test('settings save buttons are not styled with the red accent', function (string $component, string $dataTest) {
    $this->actingAs(User::factory()->create());

    $html = Livewire::test($component)->html();
    preg_match('#<button[^>]*data-test="'.$dataTest.'"[^>]*>#s', $html, $button);

    expect($button)->not->toBeEmpty()
        ->and($button[0])->not->toContain('--color-accent');
})->with([
    ['pages::settings.profile', 'update-profile-button'],
    ['pages::settings.profile', 'update-reading-profile-button'],
    ['pages::settings.privacy', 'update-privacy-button'],
    ['pages::settings.preferences', 'update-preferences-button'],
    ['pages::settings.security', 'update-password-button'],
]);
