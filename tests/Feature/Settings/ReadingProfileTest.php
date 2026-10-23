<?php

use App\Enums\ReadingLength;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake(config('filesystems.default')));

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
    $disk = Storage::disk(config('filesystems.default'));

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
