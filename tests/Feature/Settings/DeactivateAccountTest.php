<?php

use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('user can deactivate their account', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::settings.deactivate-account-modal')
        ->set('password', 'password')
        ->call('deactivateAccount')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh()->deactivated_at)->not->toBeNull()
        ->and(auth()->check())->toBeFalse();
});

test('correct password is required to deactivate', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::settings.deactivate-account-modal')
        ->set('password', 'wrong-password')
        ->call('deactivateAccount')
        ->assertHasErrors(['password']);

    expect($user->fresh()->deactivated_at)->toBeNull();
});

test('deactivating removes the users other sessions', function () {
    $this->actingAs($user = User::factory()->create());
    DB::table('sessions')->insert(['id' => 'other', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);

    Livewire::test('pages::settings.deactivate-account-modal')->set('password', 'password')->call('deactivateAccount');

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

test('deactivated users cannot log in with a password', function () {
    $user = User::factory()->deactivated()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a deactivated user with a live session is logged out on the next request', function () {
    $this->actingAs(User::factory()->deactivated()->create())
        ->get(route('home'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');

    $this->assertGuest();
});

test('purge deletes only accounts past the retention window', function () {
    $expired = User::factory()->create(['deactivated_at' => now()->subDays(31)]);
    $recent = User::factory()->create(['deactivated_at' => now()->subDays(29)]);
    $active = User::factory()->create();

    $this->artisan('bookflix:purge-deactivated-accounts')->assertSuccessful();

    expect($expired->fresh())->toBeNull()
        ->and($recent->fresh())->not->toBeNull()
        ->and($active->fresh())->not->toBeNull();
});

test('purge also deletes the avatar file', function () {
    Storage::fake(config('filesystems.default'));
    $user = User::factory()->create(['deactivated_at' => now()->subDays(31)]);
    Profile::factory()->for($user)->create(['avatar_path' => 'avatars/1/a.jpg']);
    Storage::put('avatars/1/a.jpg', 'x');

    $this->artisan('bookflix:purge-deactivated-accounts')->assertSuccessful();

    Storage::assertMissing('avatars/1/a.jpg');
});

test('logging in rehashes a password hashed with fewer rounds', function () {
    $user = User::factory()->create();
    $oldHash = $user->password;

    config(['hashing.bcrypt.rounds' => 5]);
    Hash::forgetDrivers();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticated();
    expect($user->fresh()->password)->not->toBe($oldHash);
});
