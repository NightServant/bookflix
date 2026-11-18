<?php

use App\Enums\AccessType;
use App\Models\ContentSource;
use App\Models\Media;
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
    $disk = Storage::fake(config('bookflix.avatar_disk'));
    $user = User::factory()->create(['deactivated_at' => now()->subDays(31)]);
    $user->profile()->updateOrCreate([], ['avatar_path' => 'avatars/1/a.jpg']);
    $disk->put('avatars/1/a.jpg', 'x');

    $this->artisan('bookflix:purge-deactivated-accounts')->assertSuccessful();

    $disk->assertMissing('avatars/1/a.jpg');
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

test('purge deletes every uploaded source of an account, however many there are', function () {
    Storage::fake(ContentSource::disk());
    $gone = User::factory()->create(['deactivated_at' => now()->subDays(31)]);
    $media = Media::factory()->create();
    $now = now();

    // More than one chunk of 1,000 rows: an offset walk would skip the ones past it as the earlier ones are deleted.
    foreach (array_chunk(range(1, 1100), 500) as $ids) {
        ContentSource::insert(array_map(fn (int $i): array => [
            'media_id' => $media->id,
            'access_type' => AccessType::UserOwned->value,
            'format' => 'pdf',
            'license' => 'Uploaded by its owner',
            'owner_user_id' => $gone->id,
            'external_key' => "upload:{$i}",
            'created_at' => $now,
            'updated_at' => $now,
        ], $ids));
    }

    $this->artisan('bookflix:purge-deactivated-accounts')->assertSuccessful();

    expect(ContentSource::where('media_id', $media->id)->count())->toBe(0)->and(User::find($gone->id))->toBeNull();
});
