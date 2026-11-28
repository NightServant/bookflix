<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

/*
 * bcrypt ignores every byte past the 72nd, so a longer password is refused instead of silently shortened. Bytes are counted, not characters:
 * "€" is three bytes, so 25 of them (75 bytes) are refused although they are only 25 characters.
 */

const PW_MESSAGE = 'The password must be at most 72 bytes; some characters count as more than one.';

dataset('password lengths', [
    '72 ASCII bytes' => [str_repeat('a', 72), true],
    '73 ASCII bytes' => [str_repeat('a', 73), false],
    '24 three-byte characters (72 bytes)' => [str_repeat('€', 24), true],
    '25 three-byte characters (75 bytes)' => [str_repeat('€', 25), false],
]);

test('sign-up counts the password in bytes', function (string $password, bool $accepted) {
    $response = $this->post(route('register.store'), [
        'name' => 'Real Person',
        'email' => 'real@example.com',
        'password' => $password,
        'password_confirmation' => $password,
    ]);

    if ($accepted) {
        $response->assertSessionHasNoErrors();
        expect(User::query()->where('email', 'real@example.com')->exists())->toBeTrue();
    } else {
        $response->assertSessionHasErrors(['password' => PW_MESSAGE]);
        expect(User::query()->where('email', 'real@example.com')->exists())->toBeFalse();
    }
})->with('password lengths');

test('a password reset counts the password in bytes', function (string $password, bool $accepted) {
    Notification::fake();
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $response = $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => $password,
        'password_confirmation' => $password,
    ]);

    if ($accepted) {
        $response->assertSessionHasNoErrors();
        expect(Hash::check($password, $user->refresh()->password))->toBeTrue();
    } else {
        $response->assertSessionHasErrors(['password' => PW_MESSAGE]);
        expect(Hash::check($password, $user->refresh()->password))->toBeFalse();
    }
})->with('password lengths');

test('the settings password update counts the password in bytes', function (string $password, bool $accepted) {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $this->actingAs($user);

    $page = Livewire::test('pages::settings.security')
        ->set('current_password', 'password')
        ->set('password', $password)
        ->set('password_confirmation', $password)
        ->call('updatePassword');

    if ($accepted) {
        $page->assertHasNoErrors();
        expect(Hash::check($password, $user->refresh()->password))->toBeTrue();
    } else {
        $page->assertHasErrors(['password']);
        expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
    }
})->with('password lengths');
