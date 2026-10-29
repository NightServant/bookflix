<?php

use App\Enums\ReadingStatus;
use App\Models\Genre;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\Route;

test('adding is for signed in, verified readers only', function () {
    $media = Media::factory()->create();

    $this->post(route('library.store'), ['media_id' => $media->id])->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->post(route('library.store'), ['media_id' => $media->id])->assertRedirect(route('verification.notice'));

    expect(LibraryEntry::count())->toBe(0);
});

test('the route is throttled', function () {
    $middleware = Route::getRoutes()->getByName('library.store')->gatherMiddleware();

    expect($middleware)->toContain('auth', 'verified')
        ->and(collect($middleware)->contains(fn ($name) => str_starts_with((string) $name, 'throttle:')))->toBeTrue();
});

test('a reader adds a story and goes back to where they were', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();

    $this->actingAs($user)->from(route('home'))->post(route('library.store'), ['media_id' => $media->id])
        ->assertRedirect(route('home'))
        ->assertSessionHas('status');

    $entry = $user->library()->sole();
    expect($entry->media_id)->toBe($media->id)->and($entry->status)->toBe(ReadingStatus::WantToRead);
});

test('adding twice is harmless', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();

    $this->actingAs($user)->post(route('library.store'), ['media_id' => $media->id])->assertRedirect();
    $this->actingAs($user)->post(route('library.store'), ['media_id' => $media->id])->assertRedirect()->assertSessionHasNoErrors();

    expect(LibraryEntry::count())->toBe(1);
});

test('only a story that exists can be added', function (mixed $id) {
    $this->actingAs(User::factory()->create())->post(route('library.store'), ['media_id' => $id])->assertSessionHasErrors('media_id');

    expect(LibraryEntry::count())->toBe(0);
})->with([
    'missing' => [null],
    'unknown' => [999999999],
    'not a number' => ['abc'],
    'a list' => [[1, 2]],
    'decimal' => ['1.5'],
]);

test('a deleted or merged away story cannot be added', function () {
    $deleted = Media::factory()->create();
    $deleted->delete();
    $loser = Media::factory()->create(['merged_into_id' => Media::factory()->create()->id]);
    $this->actingAs(User::factory()->create());

    $this->post(route('library.store'), ['media_id' => $deleted->id])->assertSessionHasErrors('media_id');
    $this->post(route('library.store'), ['media_id' => $loser->id])->assertSessionHasErrors('media_id');

    expect(LibraryEntry::count())->toBe(0);
});

test('a story behind the mature gate cannot be added by a reader who has not opted in, and can by one who has', function () {
    $media = Media::factory()->create();
    $media->genres()->attach(Genre::factory()->mature()->create());
    $gated = User::factory()->create();
    $open = User::factory()->showsMature()->create();

    $this->actingAs($gated)->post(route('library.store'), ['media_id' => $media->id])->assertSessionHasErrors('media_id');
    expect(LibraryEntry::count())->toBe(0);

    $this->actingAs($open)->post(route('library.store'), ['media_id' => $media->id])->assertSessionHasNoErrors();
    expect($open->library()->count())->toBe(1);
});

test('the sixty first add in a minute is turned away', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    $this->actingAs($user);

    foreach (range(1, 60) as $_) {
        $this->post(route('library.store'), ['media_id' => $media->id])->assertRedirect();
    }

    $this->post(route('library.store'), ['media_id' => $media->id])->assertStatus(429);
});
