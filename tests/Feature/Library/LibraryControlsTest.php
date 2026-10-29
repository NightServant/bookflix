<?php

use App\Actions\Library\AddToLibrary;
use App\Enums\ReadingStatus;
use App\Models\Genre;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('the controls only act for the signed in user and reject a bad status', function () {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();

    Livewire::test('library-controls', ['media' => $media])->call('setStatus', 'reading')->assertHasNoErrors();
    expect($user->library()->sole()->status)->toBe(ReadingStatus::Reading);

    Livewire::test('library-controls', ['media' => $media])->call('setStatus', 'bogus')->assertHasErrors('status');
    expect($user->library()->sole()->status)->toBe(ReadingStatus::Reading);
});

test('a story not yet in the library offers to add it, and one that is offers its status and a way out', function () {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();

    $controls = Livewire::test('library-controls', ['media' => $media])
        ->assertSee('Add to Library')
        ->assertDontSee('Remove from library');

    $controls->call('add')->assertSee('Want to read')->assertSee('Remove from library')->assertDontSee('Add to Library');
    expect($user->library()->sole()->status)->toBe(ReadingStatus::WantToRead);
});

test('the status menu lists every status as a 44px item, marks the current one, and ends with the way out', function () {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();
    app(AddToLibrary::class)->handle($user, $media, ReadingStatus::Paused);

    $html = Livewire::test('library-controls', ['media' => $media])->html();
    $items = htmlQuery($html, '//ui-menu//button[@data-flux-menu-item]');

    expect(collect($items)->map(fn (DOMElement $item): string => trim(preg_replace('/\s+/', ' ', $item->textContent)))->all())
        ->toBe(['Want to read', 'Reading', 'Completed', 'Paused', 'Abandoned', 'Remove from library'])
        ->and(htmlQuery($html, "//ui-menu//button[@*[name()='wire:click']=\"setStatus('paused')\"][@aria-current='true']")->length)->toBe(1)
        ->and(htmlQuery($html, '//ui-menu//button[@aria-current="true"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//ui-menu//button[@data-flux-menu-item][contains(@class,"min-h-11!")]')->length)->toBe(6)
        ->and(htmlQuery($html, '//button[@data-test="library-status"][normalize-space()="Paused"]')->length)->toBe(1);
});

test('favoriting adds the story and a second press removes only the favorite', function () {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();

    $controls = Livewire::test('library-controls', ['media' => $media])->call('toggleFavorite');
    expect($user->library()->sole()->favorited_at)->not->toBeNull();

    $controls->call('toggleFavorite');
    expect($user->library()->sole()->favorited_at)->toBeNull();
});

test('the favorite button says what it will do', function () {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();

    $controls = Livewire::test('library-controls', ['media' => $media]);
    expect(htmlQuery($controls->html(), '//button[@aria-pressed="false"][@aria-label="Add to favorites"]')->length)->toBe(1);

    expect(htmlQuery($controls->html(), '//button[@aria-label="Add to favorites"]//svg[@stroke="currentColor"]')->length)->toBe(1);

    $controls->call('toggleFavorite');
    expect(htmlQuery($controls->html(), '//button[@aria-pressed="true"][@aria-label="Remove from favorites"]')->length)->toBe(1)
        ->and(htmlQuery($controls->html(), '//button[@aria-label="Remove from favorites"]//svg[@fill="currentColor"]')->length)->toBe(1);
});

test('removing takes the story out and brings the add button back', function () {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();
    app(AddToLibrary::class)->handle($user, $media);

    Livewire::test('library-controls', ['media' => $media])->call('remove')->assertSee('Add to Library');

    expect(LibraryEntry::count())->toBe(0);
});

test('one reader cannot read or change another readers entry', function () {
    [$owner, $other] = User::factory()->count(2)->create();
    $media = Media::factory()->create();
    app(AddToLibrary::class)->handle($owner, $media, ReadingStatus::Completed);

    $this->actingAs($other);
    $controls = Livewire::test('library-controls', ['media' => $media])->assertSee('Add to Library')->assertDontSee('Completed');
    $controls->call('setStatus', 'abandoned')->call('toggleFavorite');
    Livewire::test('library-controls', ['media' => $media])->call('remove');

    $theirs = LibraryEntry::where('user_id', $owner->id)->sole();
    expect($theirs->status)->toBe(ReadingStatus::Completed)->and($theirs->favorited_at)->toBeNull()
        ->and(LibraryEntry::where('user_id', $other->id)->count())->toBe(0);
});

test('a guest sees no controls and cannot use them', function () {
    $media = Media::factory()->create();

    $controls = Livewire::test('library-controls', ['media' => $media])->assertDontSee('Add to Library');
    $controls->call('add')->assertForbidden();

    expect(LibraryEntry::count())->toBe(0);
});

test('a story behind the mature gate cannot be added through the controls of a reader who has not opted in', function () {
    $media = Media::factory()->create();
    $media->genres()->attach(Genre::factory()->mature()->create());
    $this->actingAs(User::factory()->create());

    Livewire::test('library-controls', ['media' => $media])->call('add')->assertNotFound();

    expect(LibraryEntry::count())->toBe(0);
});

test('the controls ask for one query to know the state', function () {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();
    app(AddToLibrary::class)->handle($user, $media);
    $user->load('profile');

    DB::enableQueryLog();
    Livewire::test('library-controls', ['media' => $media]);

    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], '"user_library"'))->count())->toBe(1);
});
