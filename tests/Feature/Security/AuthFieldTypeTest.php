<?php

use App\Models\User;

/*
 * Fortify's own controllers lower-case and hash the posted fields as strings; a list there (`email[]=x`) used to be a 500. The type guard
 * (App\Http\Middleware\RejectNonStringAuthFields) turns it into the normal validation error before any of them runs.
 */

test('a list sent for a text field of an auth form is a validation error, never a server error', function (string $route, string $field, bool $signedIn) {
    if ($signedIn) {
        $this->actingAs(User::factory()->create());
    }

    $this->from('/somewhere')->post(route($route), [
        'name' => 'Real Person',
        'email' => 'real@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'token' => 'bogus',
        'code' => '123456',
        $field => ['x'],
    ])->assertRedirect('/somewhere')->assertSessionHasErrors($field);
})->with([
    'register email' => ['register.store', 'email', false],
    'register name' => ['register.store', 'name', false],
    'register password' => ['register.store', 'password', false],
    'register confirmation' => ['register.store', 'password_confirmation', false],
    'reset link email' => ['password.email', 'email', false],
    'reset email' => ['password.update', 'email', false],
    'reset password' => ['password.update', 'password', false],
    'reset token' => ['password.update', 'token', false],
    'login email' => ['login.store', 'email', false],
    'login password' => ['login.store', 'password', false],
    'two-factor code' => ['two-factor.login.store', 'code', false],
    'two-factor recovery code' => ['two-factor.login.store', 'recovery_code', false],
    'confirm password' => ['password.confirm.store', 'password', true],
]);

// The browser follows the redirect back to the form, which re-renders with the flashed input: that page must not choke on a list either.
test('the form page the browser lands on after a list was posted renders, with the error shown', function (string $page, string $route, string $field, string $message) {
    $this->from(route($page))->followingRedirects()->post(route($route), [
        'name' => 'Real Person',
        'email' => 'real@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        $field => ['x'],
    ])->assertOk()->assertSee($message);
})->with([
    'register email' => ['register', 'register.store', 'email', 'The email field must be a string.'],
    'register name' => ['register', 'register.store', 'name', 'The name field must be a string.'],
    'register password' => ['register', 'register.store', 'password', 'The password field must be a string.'],
    'login email' => ['login', 'login.store', 'email', 'The email field must be a string.'],
    'login password' => ['login', 'login.store', 'password', 'The password field must be a string.'],
    'reset link email' => ['password.request', 'password.email', 'email', 'The email field must be a string.'],
]);

test('a list for remember on the sign-in form does not break the page it comes back to', function () {
    $this->from(route('login'))->followingRedirects()->post(route('login.store'), [
        'email' => ['x'],
        'password' => 'password',
        'remember' => ['x'],
    ])->assertOk();

    $this->from(route('login'))->followingRedirects()->post(route('login.store'), [
        'email' => 'nobody@example.com',
        'password' => 'password',
        'remember' => ['x'],
    ])->assertOk();
});

test('ordinary string posts are untouched by the type guard', function () {
    $this->post(route('register.store'), [
        'name' => 'Real Person',
        'email' => 'real2@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors()->assertRedirect(route('home', absolute: false));
});
