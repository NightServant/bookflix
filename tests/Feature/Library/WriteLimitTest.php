<?php

use App\Actions\Library\AddToLibrary;
use App\Enums\ReadingStatus;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\Rating;
use App\Models\ReadingProgress;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Use up this reader's changes for the minute, as sixty quick ones would.
 */
function exhaustLibraryWrites(User $user): void
{
    foreach (range(1, (int) config('bookflix.library.writes_per_minute')) as $ignored) {
        RateLimiter::hit("library-write:{$user->id}", 60);
    }
}

/**
 * Everything of the reader's that a change from the controls or the collection pages could touch, to compare before and after.
 *
 * @return array<string, mixed>
 */
function libraryFingerprint(User $user): array
{
    return [
        'library' => LibraryEntry::where('user_id', $user->id)->orderBy('media_id')->get(['media_id', 'status', 'favorited_at'])->toArray(),
        'ratings' => Rating::where('user_id', $user->id)->orderBy('media_id')->pluck('score', 'media_id')->all(),
        'progress' => ReadingProgress::where('user_id', $user->id)->pluck('percent', 'media_id')->all(),
        'collections' => Collection::where('user_id', $user->id)->orderBy('id')->get(['id', 'name', 'visibility'])->toArray(),
        'items' => CollectionItem::orderBy('id')->get(['id', 'collection_id', 'media_id', 'position'])->toArray(),
    ];
}

/**
 * Whether the last response from this page told the reader to slow down: a red toast, and the `limit` error.
 */
function assertSlowedDown(Testable $page): void
{
    $page->assertHasErrors(['limit'])
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger' && str_contains($params['slots']['text'], 'Give it a moment'));
}

test('the sixty-first change in a minute is refused with a toast, and nothing is written', function () {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();
    $controls = Livewire::test('library-controls', ['media' => $media]);

    expect(config('bookflix.library.writes_per_minute'))->toBe(60);

    foreach (range(1, 60) as $ignored) {
        $controls->call('setStatus', ReadingStatus::Reading->value)->assertHasNoErrors();
    }

    $controls->call('setStatus', ReadingStatus::Paused->value);

    assertSlowedDown($controls);
    expect($user->library()->sole()->status)->toBe(ReadingStatus::Reading);
});

test('a refused change does not use up more of the minute, and the minute comes round', function () {
    $this->actingAs($user = User::factory()->create());
    exhaustLibraryWrites($user);
    $controls = Livewire::test('library-controls', ['media' => Media::factory()->create()]);

    foreach (range(1, 5) as $ignored) {
        $controls->call('toggleFavorite');
    }

    expect(RateLimiter::attempts("library-write:{$user->id}"))->toBe(60)->and($user->library()->count())->toBe(0);

    $this->travel(61)->seconds();
    $controls->call('toggleFavorite')->assertHasNoErrors();

    expect($user->library()->sole()->favorited_at)->not->toBeNull();
});

test('the limit is the configured one', function () {
    config(['bookflix.library.writes_per_minute' => 2]);
    $this->actingAs($user = User::factory()->create());
    $controls = Livewire::test('library-controls', ['media' => Media::factory()->create()]);

    $controls->call('toggleFavorite')->call('toggleFavorite')->assertHasNoErrors();
    $controls->call('toggleFavorite');

    assertSlowedDown($controls);
    expect($user->library()->sole()->favorited_at)->toBeNull();
});

test('the limit is per reader: one reader using their minute up leaves another their own', function () {
    $this->actingAs($busy = User::factory()->create());
    exhaustLibraryWrites($busy);
    $media = Media::factory()->create();

    assertSlowedDown(Livewire::test('library-controls', ['media' => $media])->call('toggleFavorite'));

    $this->actingAs($calm = User::factory()->create());
    Livewire::test('library-controls', ['media' => $media])->call('toggleFavorite')->assertHasNoErrors();

    expect($calm->library()->sole()->favorited_at)->not->toBeNull()->and($busy->library()->count())->toBe(0);
});

test('the story page and the collection pages count against the same minute', function () {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();
    config(['bookflix.library.writes_per_minute' => 3]);

    Livewire::test('library-controls', ['media' => $media])->call('toggleFavorite')->call('toggleFavorite');
    Livewire::test('pages::collections.index')->set('name', 'Third')->call('create')->assertHasNoErrors();

    assertSlowedDown(Livewire::test('pages::collections.index')->set('name', 'Fourth')->call('create'));
    assertSlowedDown(Livewire::test('library-controls', ['media' => $media])->call('toggleFavorite'));
    expect($user->collections()->pluck('name')->all())->toBe(['Third']);
});

test('looking is not limited: opening and refreshing the controls and the pages uses none of the minute', function () {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();
    $collection = collectionOf($user, [$media]);
    $controls = Livewire::test('library-controls', ['media' => $media]);
    $page = Livewire::test('pages::collections.show', ['slug' => $collection->slug]);

    foreach (range(1, 70) as $ignored) {
        $controls->call('$refresh');
        $page->call('$refresh');
    }

    $controls->call('toggleFavorite')->assertHasNoErrors();
    expect(RateLimiter::attempts("library-write:{$user->id}"))->toBe(1);
});

test('every change from the story controls is held back once the minute is used up', function (Closure $change, bool $inLibrary) {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();
    $collection = Collection::factory()->for($user)->create();
    Rating::factory()->create(['user_id' => $user->id, 'media_id' => $media->id, 'score' => 5]);
    $inLibrary && app(AddToLibrary::class)->handle($user, $media);

    $controls = Livewire::test('library-controls', ['media' => $media]);
    $before = libraryFingerprint($user);
    exhaustLibraryWrites($user);

    $change($controls, $collection);

    assertSlowedDown($controls);
    expect(libraryFingerprint($user))->toBe($before);
})->with([
    'add to library' => [fn (Testable $page) => $page->call('add'), false],
    'set the status' => [fn (Testable $page) => $page->call('setStatus', 'reading'), false],
    'favorite' => [fn (Testable $page) => $page->call('toggleFavorite'), false],
    'remove from the library' => [fn (Testable $page) => $page->call('remove'), true],
    'log progress' => [fn (Testable $page) => $page->set('percent', '40')->call('logProgress'), false],
    'save a rating' => [fn (Testable $page) => $page->set('rating', 9)->call('saveRating'), false],
    'clear the rating' => [fn (Testable $page) => $page->call('clearRating'), false],
    'put the story in a collection' => [fn (Testable $page, Collection $collection) => $page->call('toggleCollection', $collection->id), false],
    'start a collection' => [fn (Testable $page) => $page->set('newCollection', 'Fresh')->call('createCollection'), false],
]);

test('every change on a collection page is held back once the minute is used up', function (string $change) {
    $this->actingAs($user = User::factory()->create());
    [$first, $second, $spare] = Media::factory()->count(3)->create();
    $collection = collectionOf($user, [$first, $second]);
    $page = Livewire::test('pages::collections.show', ['slug' => $collection->slug]);
    $before = libraryFingerprint($user);
    exhaustLibraryWrites($user);

    match ($change) {
        'add a story' => $page->call('addItem', $spare->id),
        'remove a story' => $page->call('removeItem', $collection->items()->first()->id),
        'move a story' => $page->call('reorder', $collection->items()->first()->id, 1),
        'save the details' => $page->set('name', 'Renamed')->set('visibility', 'public')->call('save'),
        'delete the collection' => $page->call('delete')->assertNoRedirect(),
    };

    assertSlowedDown($page);
    expect(libraryFingerprint($user))->toBe($before);
})->with(['add a story', 'remove a story', 'move a story', 'save the details', 'delete the collection']);

test('another reader is refused before the limit is looked at, so they cannot spend the owners minute', function () {
    $owner = User::factory()->create();
    $collection = Collection::factory()->for($owner)->create(['visibility' => 'public']);
    $this->actingAs($visitor = User::factory()->create());
    $page = Livewire::withQueryParams(['owner' => $owner->id])->test('pages::collections.show', ['slug' => $collection->slug]);

    $page->call('removeItem', 1)->assertForbidden();

    expect(RateLimiter::attempts("library-write:{$visitor->id}"))->toBe(0)->and(RateLimiter::attempts("library-write:{$owner->id}"))->toBe(0);
});
