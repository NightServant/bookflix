<?php

use App\Actions\Library\AddToLibrary;
use App\Enums\ReadingStatus;
use App\Models\Collection;
use App\Models\CollectionItem;
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
    // The first menu of the controls: the second is the collections menu.
    $items = htmlQuery($html, '(//ui-menu)[1]//button[@data-flux-menu-item]');

    expect(collect($items)->map(fn (DOMElement $item): string => trim(preg_replace('/\s+/', ' ', $item->textContent)))->all())
        ->toBe(['Want to read', 'Reading', 'Completed', 'Paused', 'Abandoned', 'Remove from library'])
        ->and(htmlQuery($html, "//ui-menu//button[@*[name()='wire:click']=\"setStatus('paused')\"][@aria-current='true']")->length)->toBe(1)
        ->and(htmlQuery($html, '//ui-menu//button[@aria-current="true"]')->length)->toBe(1)
        ->and(htmlQuery($html, '(//ui-menu)[1]//button[@data-flux-menu-item][contains(@class,"min-h-11!")]')->length)->toBe(6)
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

test('the favorite button keeps one name and says whether it is on with aria-pressed', function () {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();

    $controls = Livewire::test('library-controls', ['media' => $media]);
    expect(htmlQuery($controls->html(), '//button[@aria-pressed="false"][@aria-label="Favorite"]')->length)->toBe(1)
        ->and(htmlQuery($controls->html(), '//button[@aria-label="Favorite"]//svg[@stroke="currentColor"]')->length)->toBe(1);

    $controls->call('toggleFavorite');
    expect(htmlQuery($controls->html(), '//button[@aria-pressed="true"][@aria-label="Favorite"]')->length)->toBe(1)
        ->and(htmlQuery($controls->html(), '//button[@aria-label="Favorite"]//svg[@fill="currentColor"]')->length)->toBe(1)
        // The name does not change with the state, or a screen reader would say "pressed" on a button called "Remove".
        ->and(htmlQuery($controls->html(), '//button[@aria-label="Remove from favorites" or @aria-label="Add to favorites"]')->length)->toBe(0);
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

test('the collection menu lists the readers collections, marks those holding the story, and adds or removes it on a press', function () {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();
    $has = Collection::factory()->for($user)->create(['name' => 'Rainy Day']);
    $hasNot = Collection::factory()->for($user)->create(['name' => 'Cozy Reads']);
    CollectionItem::factory()->for($has)->for($media)->create(['position' => 0]);
    Collection::factory()->create(['name' => 'Someone Elses']);

    $controls = Livewire::test('library-controls', ['media' => $media])->assertSee('Add to collection')->assertSee('Rainy Day')->assertSee('Cozy Reads')->assertDontSee('Someone Elses');
    $items = htmlQuery($controls->html(), '//ui-menu-checkbox');

    expect($items->length)->toBe(2)
        ->and(htmlQuery($controls->html(), '//ui-menu-checkbox[@checked][contains(.,"Rainy Day")]')->length)->toBe(1)
        ->and(htmlQuery($controls->html(), '//ui-menu-checkbox[@checked][contains(.,"Cozy Reads")]')->length)->toBe(0);

    $controls->call('toggleCollection', $hasNot->id);
    expect($hasNot->items()->where('media_id', $media->id)->exists())->toBeTrue()
        ->and(htmlQuery($controls->html(), '//ui-menu-checkbox[@checked]')->length)->toBe(2);

    $controls->call('toggleCollection', $has->id);
    expect($has->items()->count())->toBe(0)->and(htmlQuery($controls->html(), '//ui-menu-checkbox[@checked]')->length)->toBe(1);

    // A collection is not the library: adding to one does not shelve the story.
    expect(LibraryEntry::count())->toBe(0);
});

test('a new collection is made and holds the story at once', function () {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();

    $controls = Livewire::test('library-controls', ['media' => $media])->set('newCollection', 'Fresh Start')->call('createCollection')->assertHasNoErrors();

    $collection = $user->collections()->sole();
    expect($collection->name)->toBe('Fresh Start')->and($collection->items()->sole()->media_id)->toBe($media->id)
        ->and($controls->get('newCollection'))->toBe('');
    $controls->assertSee('Fresh Start');
});

test('a new collection needs a name of at most 100 characters', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('library-controls', ['media' => Media::factory()->create()])
        ->set('newCollection', '')->call('createCollection')->assertHasErrors(['newCollection' => 'required'])
        ->set('newCollection', str_repeat('a', 101))->call('createCollection')->assertHasErrors(['newCollection' => 'max']);

    expect($user->collections()->count())->toBe(0);
});

test('the collection actions touch only the readers own collections and only stories they may see', function () {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();
    $foreign = Collection::factory()->create();

    Livewire::test('library-controls', ['media' => $media])->call('toggleCollection', $foreign->id)->assertNotFound();
    expect($foreign->items()->count())->toBe(0);

    $mine = Collection::factory()->for($user)->create();
    $gated = matureStory();
    Livewire::test('library-controls', ['media' => $gated])->call('toggleCollection', $mine->id)->assertNotFound();
    Livewire::test('library-controls', ['media' => $gated])->set('newCollection', 'Sneaky')->call('createCollection')->assertNotFound();

    expect($mine->items()->count())->toBe(0)->and($user->collections()->count())->toBe(1);
});

test('a guest has no collection menu and cannot use it', function () {
    $media = Media::factory()->create();
    $collection = Collection::factory()->create();

    Livewire::test('library-controls', ['media' => $media])->assertDontSee('Add to collection');
    Livewire::test('library-controls', ['media' => $media])->call('toggleCollection', $collection->id)->assertForbidden();
    Livewire::test('library-controls', ['media' => $media])->set('newCollection', 'Mine')->call('createCollection')->assertForbidden();

    expect(CollectionItem::count())->toBe(0)->and(Collection::count())->toBe(1);
});
