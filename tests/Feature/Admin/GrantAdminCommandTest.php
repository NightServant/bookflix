<?php

use App\Models\User;

test('grant admin promotes the user with that email', function () {
    $user = User::factory()->create();

    $this->artisan('bookflix:grant-admin', ['email' => $user->email])->assertSuccessful();

    expect($user->refresh()->is_admin)->toBeTrue();
});

test('grant admin fails for an unknown email', function () {
    $this->artisan('bookflix:grant-admin', ['email' => 'nobody@example.com'])->assertFailed();
});
