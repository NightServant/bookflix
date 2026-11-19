<?php

use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
});

test('two factor challenge redirects to login when not authenticated', function () {
    $response = $this->get(route('two-factor.login'));

    $response->assertRedirect(route('login'));
});

test('two factor challenge can be rendered', function () {
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));
});

test('the challenge pairs Continue with a real button to switch to a recovery code', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $html = $this->get(route('two-factor.login'))->assertOk()->getContent();
    $buttons = htmlQuery($html, '//*[@data-test="two-factor-actions"]/button');

    expect($buttons->length)->toBe(3)
        ->and(htmlQuery($html, '//*[@data-test="two-factor-actions"][contains(@class,"grid") and contains(@class,"sm:grid-cols-2")]')->length)->toBe(1)
        ->and(trim($buttons->item(1)->textContent))->toBe('Use a recovery code')
        ->and($buttons->item(1)->getAttribute('type'))->toBe('button');
});
