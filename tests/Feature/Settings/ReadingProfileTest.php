<?php

use App\Enums\ReadingLength;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake(config('filesystems.default'));
    Storage::fake(config('bookflix.avatar_disk'));
});

test('reading profile can be updated', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::settings.profile')
        ->set('bio', 'I read everything.')
        ->set('preferredLanguages', ['en', 'ja'])
        ->set('readingLength', 'short')
        ->call('updateReadingProfile')
        ->assertHasNoErrors();

    $profile = $user->refresh()->profile;
    expect($profile->bio)->toBe('I read everything.')
        ->and($profile->preferred_languages)->toBe(['en', 'ja'])
        ->and($profile->reading_length)->toBe(ReadingLength::Short);
});

test('unsupported languages are rejected', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.profile')
        ->set('preferredLanguages', ['xx'])
        ->call('updateReadingProfile')
        ->assertHasErrors(['preferredLanguages.0']);
});

test('bio is limited to 1000 characters', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.profile')
        ->set('bio', str_repeat('a', 1001))
        ->call('updateReadingProfile')
        ->assertHasErrors(['bio' => 'max']);
});

test('a new avatar replaces the previous file', function () {
    $this->actingAs($user = User::factory()->create());
    $disk = Storage::disk(config('bookflix.avatar_disk'));

    Livewire::test('pages::settings.profile')->set('avatar', UploadedFile::fake()->image('a.jpg', 400, 400))->call('updateReadingProfile');
    $first = $user->refresh()->profile->avatar_path;
    Livewire::test('pages::settings.profile')->set('avatar', UploadedFile::fake()->image('b.png', 400, 400))->call('updateReadingProfile');

    $disk->assertMissing($first);
    $disk->assertExists($user->refresh()->profile->avatar_path);
});

test('avatar must be a jpg, png or webp under 2 MB', function (UploadedFile $file) {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.profile')
        ->set('avatar', $file)
        ->call('updateReadingProfile')
        ->assertHasErrors(['avatar']);
})->with([
    'pdf' => fn () => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
    'too big' => fn () => UploadedFile::fake()->image('big.jpg')->size(3000),
]);

test('the uploaded avatar is shown in the profile component', function () {
    $this->actingAs($user = User::factory()->create());

    $component = Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->image('a.jpg', 400, 400))
        ->call('updateReadingProfile');

    $component->assertSeeHtml(User::find($user->id)->avatarUrl());
});

test('the avatar shown is refreshed after every save', function () {
    $this->actingAs($user = User::factory()->create());
    $component = Livewire::test('pages::settings.profile');

    $component->set('avatar', UploadedFile::fake()->image('a.jpg', 400, 400))->call('updateReadingProfile');
    $first = User::find($user->id)->avatarUrl();

    $component->set('avatar', UploadedFile::fake()->image('b.jpg', 400, 400))->call('updateReadingProfile');
    $second = User::find($user->id)->avatarUrl();

    expect($second)->not->toBe($first);
    $component->assertSeeHtml($second)->assertDontSeeHtml($first);
});

/**
 * Livewire swaps in a throwaway disk while testing, so assert the disk it resolves to outside tests.
 */
test('livewire temporary uploads resolve to a private disk, never the avatar disk', function () {
    $disk = config('livewire.temporary_file_upload.disk') ?: config('filesystems.default');

    expect($disk)->not->toBe(config('bookflix.avatar_disk'))
        ->and(config("filesystems.disks.{$disk}.visibility"))->not->toBe('public')
        ->and(config("filesystems.disks.{$disk}.url"))->toBeNull();
});

test('the avatar url comes from the configured avatar disk', function () {
    config([
        'filesystems.disks.cdn' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/cdn'), 'url' => 'https://cdn.test'],
        'bookflix.avatar_disk' => 'cdn',
    ]);
    $user = User::factory()->create();
    $user->profile()->updateOrCreate([], ['avatar_path' => 'avatars/1/a.jpg']);

    expect($user->avatarUrl())->toBe('https://cdn.test/avatars/1/a.jpg');
});
