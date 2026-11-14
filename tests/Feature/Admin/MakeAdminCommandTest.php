<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Exception\InvalidOptionException;

/** The printed password: the only line that holds it, so a test can read it back. */
function printedPassword(string $output): string
{
    preg_match('/^Password: (\S+)$/m', $output, $matches);

    return $matches[1] ?? '';
}

it('creates a verified, onboarded admin with a generated password', function () {
    $this->artisan('bookflix:make-admin', ['email' => 'ops@example.test', '--name' => 'Ops'])->assertSuccessful();

    $user = User::where('email', 'ops@example.test')->firstOrFail();

    expect($user->name)->toBe('Ops')
        ->and($user->is_admin)->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->profile->onboarded_at)->not->toBeNull()
        ->and(Gate::forUser($user)->allows('access-admin'))->toBeTrue();
});

it('names the account Bookflix Admin by default', function () {
    $this->artisan('bookflix:make-admin', ['email' => 'ops@example.test'])->assertSuccessful();

    expect(User::where('email', 'ops@example.test')->firstOrFail()->name)->toBe('Bookflix Admin');
});

it('tells the operator to store the password and change it', function () {
    $this->artisan('bookflix:make-admin', ['email' => 'ops@example.test'])
        ->expectsOutputToContain('Change it after the first sign-in')
        ->assertSuccessful();
});

it('stores a hash that verifies against the printed password, which meets the password rules', function () {
    $this->withoutMockingConsoleOutput();

    $exit = Artisan::call('bookflix:make-admin', ['email' => 'ops@example.test']);
    $password = printedPassword(Artisan::output());

    expect($exit)->toBe(0)
        ->and(strlen($password))->toBeGreaterThanOrEqual(24)
        ->and($password)->toMatch('/[A-Za-z]/')->toMatch('/[0-9]/')->toMatch('/[^A-Za-z0-9]/')
        ->and(Validator::make(['password' => $password], ['password' => Password::min(12)->mixedCase()->letters()->numbers()->symbols()])->passes())->toBeTrue();

    $stored = User::where('email', 'ops@example.test')->firstOrFail()->password;

    expect($stored)->not->toBe($password)
        ->and(Hash::isHashed($stored))->toBeTrue()
        ->and(Hash::check($password, $stored))->toBeTrue();
});

it('does not log the password and accepts none on the command line', function () {
    Log::spy();

    $this->withoutMockingConsoleOutput();
    Artisan::call('bookflix:make-admin', ['email' => 'ops@example.test']);
    $password = printedPassword(Artisan::output());

    Log::shouldNotHaveReceived('info');
    expect($password)->not->toBe('');

    expect(fn () => Artisan::call('bookflix:make-admin', ['email' => 'other@example.test', '--password' => 'abc']))
        ->toThrow(InvalidOptionException::class);
});

it('fails without changing anything when the email already exists', function () {
    $reader = User::factory()->create(['email' => 'reader@example.test']);
    $hash = $reader->password;

    $this->artisan('bookflix:make-admin', ['email' => 'reader@example.test'])
        ->expectsOutputToContain('bookflix:grant-admin')
        ->assertFailed();

    $reader->refresh();

    expect($reader->is_admin)->toBeFalse()
        ->and($reader->password)->toBe($hash)
        ->and(User::count())->toBe(1);
});

it('fails for an invalid email', function () {
    $this->artisan('bookflix:make-admin', ['email' => 'not-an-email'])->assertFailed();

    expect(User::count())->toBe(0);
});
