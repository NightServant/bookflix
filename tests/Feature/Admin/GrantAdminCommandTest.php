<?php

use App\Models\User;

test('grant admin promotes the user with that email', function () {
    $user = User::factory()->create();

    $this->artisan('bookflix:grant-admin', ['email' => $user->email])->expectsOutputToContain('reader side')
        ->assertSuccessful();

    expect($user->refresh()->is_admin)->toBeTrue();
});

test('grant admin fails for an unknown email', function () {
    $this->artisan('bookflix:grant-admin', ['email' => 'nobody@example.com'])->assertFailed();
});

test('revoke makes an admin a reader again, who reaches Home and no admin page', function () {
    $other = User::factory()->admin()->create();
    $user = User::factory()->admin()->create();

    $this->artisan('bookflix:grant-admin', ['email' => $user->email, '--revoke' => true])
        ->expectsOutputToContain('is now a reader')->doesntExpectOutputToContain('No admin account is left')->assertSuccessful();

    expect($user->refresh()->is_admin)->toBeFalse()->and($other->refresh()->is_admin)->toBeTrue();

    $this->actingAs($user)->get(route('home'))->assertOk();
    $this->actingAs($user)->get(route('admin.index'))->assertForbidden();
});

test('revoking the last admin warns that none is left', function () {
    $user = User::factory()->admin()->create();

    $this->artisan('bookflix:grant-admin', ['email' => $user->email, '--revoke' => true])
        ->expectsOutputToContain('No admin account is left')->assertSuccessful();
});

test('revoke fails for an unknown email', function () {
    $this->artisan('bookflix:grant-admin', ['email' => 'nobody@example.com', '--revoke' => true])->assertFailed();
});
