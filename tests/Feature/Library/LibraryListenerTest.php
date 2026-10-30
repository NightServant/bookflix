<?php

use App\Models\Genre;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\User;
use App\Models\UserInteraction;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

test('the signed in layout carries the listener, so every page can add a story', function () {
    $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()->assertSeeLivewire('library-listener');
});

test('the library-add event adds the story for the signed in reader and confirms with a toast', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create(['title' => 'Frankenstein']);
    $this->actingAs($user);

    Livewire::test('library-listener')
        ->dispatch('library-add', id: $media->id)
        ->assertDispatched('toast-show');

    expect($user->library()->sole()->media_id)->toBe($media->id)
        ->and(interactionEvents())->toBe(['library_add']);
});

test('the toast names the story, and says so when it was already in the library', function () {
    $this->actingAs(User::factory()->create());
    $media = Media::factory()->create(['title' => 'Frankenstein']);

    $first = Livewire::test('library-listener')->dispatch('library-add', id: $media->id);
    $again = Livewire::test('library-listener')->dispatch('library-add', id: $media->id);

    $first->assertDispatched('toast-show', fn (string $event, array $params): bool => str_contains($params['slots']['text'], 'Frankenstein') && ($params['dataset']['variant'] ?? null) === 'success');
    $again->assertDispatched('toast-show', fn (string $event, array $params): bool => str_contains($params['slots']['text'], 'already'));
    expect(LibraryEntry::count())->toBe(1);
});

test('a story the reader may not see, or that does not exist, is not added', function (string $case) {
    $media = Media::factory()->create();
    $media->genres()->attach(Genre::factory()->mature()->create());
    $this->actingAs(User::factory()->create());
    $id = match ($case) {
        'gated' => $media->id,
        'unknown' => 999999999,
        'not a number' => 'abc',
        'null' => null,
    };

    Livewire::test('library-listener')->dispatch('library-add', id: $id)->assertHasNoErrors()
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger');

    expect(LibraryEntry::count())->toBe(0);
})->with(['gated', 'unknown', 'not a number', 'null']);

test('a reader who opted in to mature titles can add one', function () {
    $user = User::factory()->showsMature()->create();
    $media = Media::factory()->create();
    $media->genres()->attach(Genre::factory()->mature()->create());
    $this->actingAs($user);

    Livewire::test('library-listener')->dispatch('library-add', id: $media->id);

    expect($user->library()->count())->toBe(1);
});

test('nobody is signed in: nothing is added', function () {
    $media = Media::factory()->create();

    Livewire::test('library-listener')->dispatch('library-add', id: $media->id);

    expect(LibraryEntry::count())->toBe(0);
});

test('an opted out reader adds stories without leaving interactions', function () {
    $user = User::factory()->create();
    $user->profile()->updateOrCreate([], ['personalization_enabled' => false]);
    $this->actingAs($user);

    Livewire::test('library-listener')->dispatch('library-add', id: Media::factory()->create()->id);

    expect(LibraryEntry::count())->toBe(1)->and(UserInteraction::count())->toBe(0);
});

test('the listener is held to the same limit as the route', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    RateLimiter::increment('library-add:'.$user->id, amount: 60);

    Livewire::test('library-listener')->dispatch('library-add', id: Media::factory()->create()->id)
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger');

    expect(LibraryEntry::count())->toBe(0);
});

test('the toast that confirms an add has a named close button', function () {
    expect(htmlQuery(renderedComponent('<flux:toast />'), '//button[@aria-label="Close"]')->length)->toBe(1);
});
