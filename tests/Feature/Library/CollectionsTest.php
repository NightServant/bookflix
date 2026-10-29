<?php

use App\Discovery\EditorialCollections;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The titles a collection page lists, in order.
 *
 * @return list<string>
 */
function collectionTitles(Testable $page): array
{
    return $page->instance()->items->map(fn (CollectionItem $item): string => $item->media->title)->all();
}

test('the collections pages are for signed in, verified readers', function () {
    $collection = Collection::factory()->create();

    $this->get(route('collections.index'))->assertRedirect(route('login'));
    $this->get(route('collections.show', $collection->slug))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->get(route('collections.index'))->assertRedirect(route('verification.notice'));
    $this->actingAs(User::factory()->create())->get(route('collections.index'))->assertOk()->assertSeeLivewire('pages::collections.index');
});

test('users create collections with a unique slug per owner', function () {
    $this->actingAs($a = User::factory()->create());

    Livewire::test('pages::collections.index')->set('name', 'Favorites')->call('create');
    Livewire::test('pages::collections.index')->set('name', 'Favorites')->call('create');

    expect($a->collections()->orderBy('id')->pluck('slug')->all())->toBe(['favorites', 'favorites-2']);

    // Another reader's "Favorites" is their own, and starts at the plain slug.
    $this->actingAs($b = User::factory()->create());
    Livewire::test('pages::collections.index')->set('name', 'Favorites')->call('create');

    expect($b->collections()->pluck('slug')->all())->toBe(['favorites']);
});

test('creating a collection opens it, and a name with no letters still gets a slug', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::collections.index')->set('name', '!!!')->call('create')->assertRedirect(route('collections.show', 'collection'));

    expect($user->collections()->sole()->name)->toBe('!!!');
});

test('names are required and limited to 100 characters', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::collections.index')->set('name', '')->call('create')->assertHasErrors(['name' => 'required'])
        ->set('name', '   ')->call('create')->assertHasErrors(['name' => 'required'])
        ->set('name', str_repeat('a', 101))->call('create')->assertHasErrors(['name' => 'max'])
        ->set('name', str_repeat('a', 100))->call('create')->assertHasNoErrors();

    expect($user->collections()->count())->toBe(1);
});

test('the index lists only the readers own collections, with the stories they may see', function () {
    $this->actingAs($user = User::factory()->create());
    collectionOf($user, [Media::factory()->create(), Media::factory()->create()], ['name' => 'Rainy Day']);
    collectionOf($user, [], ['name' => 'Empty Shelf']);
    Collection::factory()->create(['name' => 'Someone Elses']);

    Livewire::test('pages::collections.index')
        ->assertSee('Rainy Day')->assertSee('2 stories')
        ->assertSee('Empty Shelf')->assertSee('No stories yet')
        ->assertDontSee('Someone Elses');
});

test('a story behind the mature gate is not counted, shown or covered in the index until the reader opts in', function () {
    $user = User::factory()->create();
    $plain = Media::factory()->create(['cover_url' => 'https://covers.test/plain.jpg']);
    $mature = matureStory();
    $mature->update(['cover_url' => 'https://covers.test/mature.jpg']);
    collectionOf($user, [$mature, $plain], ['name' => 'Mixed']);

    $gated = Livewire::actingAs($user)->test('pages::collections.index');
    expect($gated->html())->toContain('1 story')->toContain('plain.jpg')->not->toContain('mature.jpg')->not->toContain('2 stories');

    $user->profile()->updateOrCreate([], ['show_mature' => true, 'adult_confirmed_at' => now()]);
    $open = Livewire::actingAs($user->fresh())->test('pages::collections.index');
    expect($open->html())->toContain('2 stories')->toContain('mature.jpg');
});

test('the index runs a bounded number of queries', function () {
    $user = User::factory()->create();

    foreach (range(1, 12) as $n) {
        collectionOf($user, Media::factory()->count(3)->create(['cover_url' => 'https://covers.test/c.jpg'])->all(), ['name' => "List {$n}"]);
    }

    $this->actingAs($user);
    DB::enableQueryLog();
    $this->get(route('collections.index'))->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(16);
});

test('only the owner can edit or view a private collection', function () {
    $c = Collection::factory()->create(['visibility' => 'private']);
    $this->actingAs($other = User::factory()->create());

    $this->get(route('collections.show', $c->slug))->assertNotFound();
    expect(Gate::allows('update', $c))->toBeFalse()->and(Gate::allows('view', $c))->toBeFalse();

    $this->actingAs($c->user)->get(route('collections.show', $c->slug))->assertOk();
    expect(Gate::allows('update', $c))->toBeTrue();
});

test('the policy and the lookup agree on who may view which collection', function (string $kind, bool $allowed) {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $collection = match ($kind) {
        'own' => Collection::factory()->for($viewer)->create(['visibility' => 'private']),
        'private' => Collection::factory()->for($owner)->create(['visibility' => 'private']),
        'public' => Collection::factory()->for($owner)->create(['visibility' => 'public']),
        'public, owner deactivated' => Collection::factory()->for(User::factory()->deactivated())->create(['visibility' => 'public']),
        'editorial' => Collection::factory()->for($owner)->create(['visibility' => 'private', 'is_editorial' => true]),
    };

    expect(Gate::forUser($viewer)->allows('view', $collection))->toBe($allowed)
        ->and(Collection::resolveFor($collection->slug, $viewer)?->is($collection) ?? false)->toBe($allowed);
})->with([
    'own' => ['own', true],
    'someone elses private one' => ['private', false],
    'a public one' => ['public', true],
    'a public one of a deactivated reader' => ['public, owner deactivated', false],
    'an editorial one' => ['editorial', true],
]);

test('only the owner may change a collection, even a public or editorial one', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $public = Collection::factory()->for($owner)->create(['visibility' => 'public']);
    $editorial = Collection::factory()->for($owner)->create(['is_editorial' => true]);

    foreach ([$public, $editorial] as $collection) {
        foreach (['update', 'delete', 'addItem'] as $ability) {
            expect(Gate::forUser($other)->allows($ability, $collection))->toBeFalse()->and(Gate::forUser($owner)->allows($ability, $collection))->toBeTrue();
        }
    }
});

test('your own collection wins an ambiguous slug, then an editorial one, then a public one', function () {
    $me = User::factory()->create();
    $public = Collection::factory()->create(['name' => 'Classics', 'visibility' => 'public']);
    $editorial = Collection::factory()->create(['name' => 'Classics', 'is_editorial' => true]);

    expect(Collection::resolveFor('classics', $me)->is($editorial))->toBeTrue();

    $mine = Collection::factory()->for($me)->create(['name' => 'Classics']);
    expect(Collection::resolveFor('classics', $me)->is($mine))->toBeTrue();

    // A link to the staff pick means the staff pick, whatever the reader has called their own.
    expect(Collection::resolveFor('classics', $me, editorial: true)->is($editorial))->toBeTrue();

    $editorial->delete();
    $mine->delete();
    expect(Collection::resolveFor('classics', $me)->is($public))->toBeTrue()
        ->and(Collection::resolveFor('nothing-here', $me))->toBeNull();
});

test('a link to a staff pick opens the staff pick, not the readers own collection of the same name', function () {
    $me = User::factory()->create();
    $mine = collectionOf($me, [Media::factory()->create(['title' => 'My Own Tale'])], ['name' => 'Classics']);
    $pick = collectionOf(User::factory()->admin()->create(), [Media::factory()->create(['title' => 'Staff Tale'])], ['name' => 'Classics', 'is_editorial' => true]);

    $this->actingAs($me);
    $plain = Livewire::test('pages::collections.show', ['slug' => 'classics']);
    $linked = Livewire::withQueryParams(['editors' => 1])->test('pages::collections.show', ['slug' => 'classics']);

    expect(collectionTitles($plain))->toBe(['My Own Tale'])->and($plain->instance()->collection->is($mine))->toBeTrue()
        ->and(collectionTitles($linked))->toBe(['Staff Tale'])->and($linked->instance()->collection->is($pick))->toBeTrue();
});

test('items can be added once and removed', function () {
    $this->actingAs($u = User::factory()->create());
    $c = Collection::factory()->for($u)->create();
    $m = Media::factory()->create();

    $lw = Livewire::test('pages::collections.show', ['slug' => $c->slug])->call('addItem', $m->id)->call('addItem', $m->id);
    expect($c->items()->count())->toBe(1);

    $lw->call('removeItem', $c->items()->first()->id);
    expect($c->items()->count())->toBe(0);
});

test('new items go to the end, and a removal closes the gap', function () {
    $this->actingAs($u = User::factory()->create());
    [$a, $b, $d] = Media::factory()->count(3)->create();
    $c = collectionOf($u, [$a, $b]);

    $lw = Livewire::test('pages::collections.show', ['slug' => $c->slug])->call('addItem', $d->id);
    expect($c->items()->orderBy('position')->pluck('media_id')->all())->toBe([$a->id, $b->id, $d->id]);

    $lw->call('removeItem', $c->items()->where('media_id', $a->id)->value('id'));
    expect($c->items()->orderBy('position')->pluck('media_id')->all())->toBe([$b->id, $d->id])
        ->and($c->items()->orderBy('position')->pluck('position')->all())->toBe([0, 1]);
});

test('a story that is gone, merged away or behind the mature gate cannot be added', function () {
    $this->actingAs($u = User::factory()->create());
    $c = Collection::factory()->for($u)->create();
    $merged = Media::factory()->create(['merged_into_id' => Media::factory()->create()->id]);

    foreach ([matureStory()->id, $merged->id, 999999] as $id) {
        Livewire::test('pages::collections.show', ['slug' => $c->slug])->call('addItem', $id)->assertNotFound();
    }

    expect($c->items()->count())->toBe(0);
});

test('a collection holds a limited number of stories', function () {
    config(['bookflix.collections.max_items' => 2]);
    $this->actingAs($user = User::factory()->create());
    $c = collectionOf($user, Media::factory()->count(2)->create()->all());

    Livewire::test('pages::collections.show', ['slug' => $c->slug])->call('addItem', Media::factory()->create()->id)->assertHasErrors('collection');

    expect($c->items()->count())->toBe(2);
});

test('reordering rewrites contiguous positions', function () {
    $this->actingAs($u = User::factory()->create());
    $c = Collection::factory()->for($u)->create();
    [$a, $b, $d] = collect(range(1, 3))->map(fn ($i) => CollectionItem::factory()->for($c)->create(['position' => $i - 1]))->all();

    Livewire::test('pages::collections.show', ['slug' => $c->slug])->call('reorder', $d->id, 0);

    expect($c->items()->orderBy('position')->pluck('id')->all())->toBe([$d->id, $a->id, $b->id])
        ->and($c->items()->orderBy('position')->pluck('position')->all())->toBe([0, 1, 2]);
});

test('a story moves down, to the end, and a position past either end is clamped', function (int $move, int $to, array $expected) {
    $this->actingAs($u = User::factory()->create());
    $stories = Media::factory()->count(4)->sequence(fn ($sequence) => ['title' => chr(65 + $sequence->index)])->create()->all();
    $c = collectionOf($u, $stories);
    $item = $c->items()->where('media_id', $stories[$move]->id)->first();

    $page = Livewire::test('pages::collections.show', ['slug' => $c->slug])->call('reorder', $item->id, $to);

    expect(collectionTitles($page))->toBe($expected)
        ->and($c->items()->orderBy('position')->pluck('position')->all())->toBe([0, 1, 2, 3]);
})->with([
    'first to second' => [0, 1, ['B', 'A', 'C', 'D']],
    'first to the end' => [0, 3, ['B', 'C', 'D', 'A']],
    'last to the middle' => [3, 1, ['A', 'D', 'B', 'C']],
    'past the end' => [1, 99, ['A', 'C', 'D', 'B']],
    'before the start' => [2, -5, ['C', 'A', 'B', 'D']],
    'to where it already is' => [2, 2, ['A', 'B', 'C', 'D']],
]);

test('every item has move up and move down buttons that work from the keyboard, the first without up and the last without down', function () {
    $this->actingAs($u = User::factory()->create());
    $stories = Media::factory()->count(3)->sequence(fn ($sequence) => ['title' => chr(65 + $sequence->index)])->create()->all();
    $c = collectionOf($u, $stories);

    $page = Livewire::test('pages::collections.show', ['slug' => $c->slug]);
    $html = $page->html();

    expect(htmlQuery($html, '//button[starts-with(@aria-label,"Move")][contains(@aria-label,"up")]')->length)->toBe(2)
        ->and(htmlQuery($html, '//button[starts-with(@aria-label,"Move")][contains(@aria-label,"down")]')->length)->toBe(2)
        ->and(htmlQuery($html, '//button[@aria-label="Move B up"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//button[@aria-label="Move A up"]')->length)->toBe(0)
        ->and(htmlQuery($html, '//button[@aria-label="Move C down"]')->length)->toBe(0)
        ->and(htmlQuery($html, '//ul[@*[name()="wire:sort"]="reorder"]')->length)->toBe(1);

    // The buttons call the same action the drag does: the item and the position it should take.
    $second = $c->items()->where('media_id', $stories[1]->id)->value('id');
    $page->call('reorder', $second, 0);
    expect(collectionTitles($page))->toBe(['B', 'A', 'C']);
});

test('reordering with stories hidden by the mature gate keeps the hidden ones where they were relative to their neighbours', function () {
    $this->actingAs($u = User::factory()->create());
    [$a, $b, $d] = Media::factory()->count(3)->sequence(fn ($sequence) => ['title' => chr(65 + $sequence->index)])->create()->all();
    $hidden = matureStory();
    $c = collectionOf($u, [$a, $hidden, $b, $d]);

    // The reader sees A, B, C and drags C to the front.
    $page = Livewire::test('pages::collections.show', ['slug' => $c->slug]);
    expect(collectionTitles($page))->toBe(['A', 'B', 'C']);
    $page->call('reorder', $c->items()->where('media_id', $d->id)->value('id'), 0);

    expect(collectionTitles($page))->toBe(['C', 'A', 'B'])
        ->and($c->items()->orderBy('position')->pluck('media_id')->all())->toBe([$d->id, $a->id, $hidden->id, $b->id])
        ->and($c->items()->orderBy('position')->pluck('position')->all())->toBe([0, 1, 2, 3]);
});

test('another users item ids cannot be reordered or removed', function () {
    $this->actingAs(User::factory()->create());
    $mine = Collection::factory()->for(auth()->user())->create();
    $mineItem = CollectionItem::factory()->for($mine)->create(['position' => 0]);
    $foreign = CollectionItem::factory()->create(['position' => 0]);

    $page = Livewire::test('pages::collections.show', ['slug' => $mine->slug]);
    $page->call('removeItem', $foreign->id)->call('reorder', $foreign->id, 5);

    expect(CollectionItem::count())->toBe(2)->and($foreign->fresh()->position)->toBe(0)->and($mineItem->fresh()->position)->toBe(0);
});

test('another reader cannot change a collection they can only view', function () {
    $owner = User::factory()->create();
    $c = collectionOf($owner, [Media::factory()->create()], ['visibility' => 'public', 'name' => 'Theirs']);
    $item = $c->items()->first();

    $this->actingAs(User::factory()->create());
    $story = Media::factory()->create();

    Livewire::test('pages::collections.show', ['slug' => $c->slug])->assertDontSee('Add stories')->assertDontSee('Delete collection');
    Livewire::test('pages::collections.show', ['slug' => $c->slug])->call('removeItem', $item->id)->assertForbidden();
    Livewire::test('pages::collections.show', ['slug' => $c->slug])->call('addItem', $story->id)->assertForbidden();
    Livewire::test('pages::collections.show', ['slug' => $c->slug])->call('reorder', $item->id, 0)->assertForbidden();
    Livewire::test('pages::collections.show', ['slug' => $c->slug])->set('name', 'Mine now')->call('save')->assertForbidden();
    Livewire::test('pages::collections.show', ['slug' => $c->slug])->call('delete')->assertForbidden();

    expect($c->fresh()->name)->toBe('Theirs')->and($c->items()->count())->toBe(1);
});

test('the owner edits the details, and the slug stays so links keep working', function () {
    $this->actingAs($u = User::factory()->create());
    $c = Collection::factory()->for($u)->create(['name' => 'Old name', 'slug' => 'old-name', 'visibility' => 'private']);

    Livewire::test('pages::collections.show', ['slug' => 'old-name'])
        ->set('name', 'Autumn reads')->set('description', 'Cosy ones.')->set('visibility', 'public')->call('save')->assertHasNoErrors();

    $c->refresh();
    expect($c->name)->toBe('Autumn reads')->and($c->description)->toBe('Cosy ones.')->and($c->visibility->value)->toBe('public')->and($c->slug)->toBe('old-name');
});

test('the details are validated', function () {
    $this->actingAs($u = User::factory()->create());
    $c = Collection::factory()->for($u)->create();

    Livewire::test('pages::collections.show', ['slug' => $c->slug])
        ->set('name', '')->call('save')->assertHasErrors(['name' => 'required'])
        ->set('name', str_repeat('a', 101))->call('save')->assertHasErrors(['name' => 'max'])
        ->set('name', 'Fine')->set('description', str_repeat('a', 501))->call('save')->assertHasErrors(['description' => 'max'])
        ->set('description', '')->set('visibility', 'friends')->call('save')->assertHasErrors('visibility');
});

test('only admins can mark a collection editorial', function () {
    $c = Collection::factory()->create();
    Livewire::actingAs($c->user)->test('pages::collections.show', ['slug' => $c->slug])->set('isEditorial', true)->call('save');
    expect($c->fresh()->is_editorial)->toBeFalse();

    $admin = User::factory()->admin()->create();
    $e = Collection::factory()->for($admin)->create();
    Livewire::actingAs($admin)->test('pages::collections.show', ['slug' => $e->slug])->set('isEditorial', true)->call('save');
    expect($e->fresh()->is_editorial)->toBeTrue();
});

test('the editorial switch is on the page for admins only', function () {
    $c = Collection::factory()->create();

    expect(Livewire::actingAs($c->user)->test('pages::collections.show', ['slug' => $c->slug])->html())->not->toContain('Show as an editor pick');

    $admin = User::factory()->admin()->create();
    $e = Collection::factory()->for($admin)->create();
    expect(Livewire::actingAs($admin)->test('pages::collections.show', ['slug' => $e->slug])->html())->toContain('Show as an editor pick');
});

test('editorial collections are viewable by everyone, deleting a collection removes its items', function () {
    $c = Collection::factory()->create(['is_editorial' => true, 'visibility' => 'private']);
    $this->actingAs(User::factory()->create())->get(route('collections.show', $c->slug))->assertOk();
    CollectionItem::factory()->for($c)->create();
    $c->delete();

    expect(CollectionItem::count())->toBe(0);
});

test('the owner deletes a collection and its stories stay in the catalog', function () {
    $this->actingAs($u = User::factory()->create());
    $story = Media::factory()->create();
    $c = collectionOf($u, [$story]);

    Livewire::test('pages::collections.show', ['slug' => $c->slug])->call('delete')->assertRedirect(route('collections.index'));

    expect(Collection::count())->toBe(0)->and(CollectionItem::count())->toBe(0)->and(Media::find($story->id))->not->toBeNull();
});

test('a collection page opens with a guiding description, and a viewer sees the stories as a grid they cannot edit', function () {
    $owner = User::factory()->create();
    $c = collectionOf($owner, [Media::factory()->create(['title' => 'Shared Tale'])], ['name' => 'Open List', 'visibility' => 'public']);

    $html = $this->actingAs(User::factory()->create())->get(route('collections.show', $c->slug))->assertOk()->getContent();

    expect(htmlQuery($html, '//main//h1')->length)->toBe(1)
        ->and(htmlQuery($html, '//main//h1')->item(0)->textContent)->toContain('Open List')
        ->and($html)->toContain('Shared Tale')->not->toContain('wire:sort')
        ->and(htmlQuery($html, '//a[@href="'.route('stories.show', Media::where('title', 'Shared Tale')->value('slug')).'"]')->length)->toBeGreaterThan(0);
});

test('a story behind the mature gate is hidden in your own collection until you opt in, and the page does not count it', function () {
    $user = User::factory()->create();
    $mature = matureStory();
    $c = collectionOf($user, [Media::factory()->create(['title' => 'Plain Tale']), $mature], ['name' => 'Mixed']);

    $gated = Livewire::actingAs($user)->test('pages::collections.show', ['slug' => $c->slug]);
    expect(collectionTitles($gated))->toBe(['Plain Tale'])
        ->and($gated->html())->not->toContain('Erotic Tale')->toContain('1 story')->not->toContain('2 stories');

    $user->profile()->updateOrCreate([], ['show_mature' => true, 'adult_confirmed_at' => now()]);
    $open = Livewire::actingAs($user->fresh())->test('pages::collections.show', ['slug' => $c->slug]);
    expect(collectionTitles($open))->toBe(['Plain Tale', 'Erotic Tale'])->and($open->html())->toContain('2 stories');
});

test('a story behind the mature gate is hidden in a public or editorial collection from a reader who has not opted in', function (array $attributes) {
    $c = collectionOf(User::factory()->create(), [Media::factory()->create(['title' => 'Plain Tale']), matureStory()], ['name' => 'Shared', ...$attributes]);

    $gated = Livewire::actingAs(User::factory()->create())->test('pages::collections.show', ['slug' => $c->slug]);
    expect(collectionTitles($gated))->toBe(['Plain Tale'])->and($gated->html())->not->toContain('Erotic Tale');

    $open = Livewire::actingAs(User::factory()->showsMature()->create())->test('pages::collections.show', ['slug' => $c->slug]);
    expect(collectionTitles($open))->toBe(['Plain Tale', 'Erotic Tale']);
})->with([
    'public' => [['visibility' => 'public']],
    'editorial' => [['is_editorial' => true]],
]);

test('the add stories panel offers the readers library when nothing is typed, and finds stories by what is typed', function () {
    $this->actingAs($user = User::factory()->create());
    $inLibrary = Media::factory()->create(['title' => 'Saved Story']);
    $user->library()->create(['media_id' => $inLibrary->id]);
    $alreadyIn = Media::factory()->create(['title' => 'Already Here']);
    $user->library()->create(['media_id' => $alreadyIn->id]);
    Media::factory()->create(['title' => 'Moonlight Sonata']);
    $c = collectionOf($user, [$alreadyIn]);

    $page = Livewire::test('pages::collections.show', ['slug' => $c->slug]);
    expect(collect($page->instance()->suggestions)->pluck('title')->all())->toBe(['Saved Story']);
    $page->assertSee('From your library');

    $page->set('find', 'Moonlight');
    expect(collect($page->instance()->suggestions)->pluck('title')->all())->toBe(['Moonlight Sonata']);

    $page->call('addItem', $page->instance()->suggestions->first()->id);
    expect($c->items()->count())->toBe(2);
});

test('the add stories panel never offers a story behind the mature gate', function () {
    $this->actingAs($user = User::factory()->create());
    $mature = matureStory('Erotic Moonlight');
    $user->library()->create(['media_id' => $mature->id]);
    $c = Collection::factory()->for($user)->create();

    $page = Livewire::test('pages::collections.show', ['slug' => $c->slug]);
    expect($page->instance()->suggestions)->toBeEmpty();

    $page->set('find', 'Erotic Moonlight');
    expect($page->instance()->suggestions)->toBeEmpty();
});

test('a collection page runs a bounded number of queries', function () {
    $user = User::factory()->create();
    $c = collectionOf($user, Media::factory()->count(30)->create()->all());

    $this->actingAs($user);
    DB::enableQueryLog();
    $this->get(route('collections.show', $c->slug))->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(18);
});

test('editorial collections surface on discover with their theme', function () {
    $c = collectionOf(User::factory()->admin()->create(), [Media::factory()->create(['title' => 'Haunted Tale'])], [
        'name' => 'Spooky Season', 'description' => 'Stories to read with the lights on.', 'is_editorial' => true,
    ]);
    collectionOf(User::factory()->create(), [Media::factory()->create(['title' => 'Private Tale'])], ['name' => 'Not Editorial']);

    $html = $this->actingAs(User::factory()->create())->get(route('discover'))->getContent();

    expect($html)->toContain('Spooky Season')->toContain('Staff pick')->toContain('Stories to read with the lights on.')->toContain('Haunted Tale')
        ->not->toContain('Not Editorial')->not->toContain('Private Tale')
        ->and(htmlQuery($html, '//a[@href="'.route('collections.show', ['slug' => $c->slug, 'editors' => 1]).'"]')->length)->toBeGreaterThan(0);
});

test('editorial collections are a shelf row on home too, between the story shelves and the subjects', function () {
    collectionOf(User::factory()->admin()->create(), [Media::factory()->create(['title' => 'Haunted Tale'])], ['name' => 'Spooky Season', 'is_editorial' => true]);

    $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()->assertSee('Spooky Season')->assertSee('Staff pick');
});

test('the editorial row is left out when there is none, and an editorial collection with nothing the reader may see is left out too', function () {
    $this->actingAs($user = User::factory()->create());
    Media::factory()->create();

    expect($this->get(route('discover'))->getContent())->not->toContain('Staff pick');

    collectionOf(User::factory()->admin()->create(), [matureStory()], ['name' => 'Only Mature', 'is_editorial' => true]);
    collectionOf(User::factory()->admin()->create(), [], ['name' => 'Empty Picks', 'is_editorial' => true]);

    expect($this->get(route('discover'))->getContent())->not->toContain('Only Mature')->not->toContain('Empty Picks');

    $this->actingAs(User::factory()->showsMature()->create());
    expect($this->get(route('discover'))->getContent())->toContain('Only Mature')->not->toContain('Empty Picks');
});

test('a story behind the mature gate is not on the home row of an editorial collection until the reader opts in', function () {
    collectionOf(User::factory()->admin()->create(), [Media::factory()->create(['title' => 'Plain Pick']), matureStory('Erotic Pick')], ['name' => 'Spooky Season', 'is_editorial' => true]);

    $gated = $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()->getContent();
    expect($gated)->toContain('Plain Pick')->not->toContain('Erotic Pick');

    $open = $this->actingAs(User::factory()->showsMature()->create())->get(route('home'))->assertOk()->getContent();
    expect($open)->toContain('Erotic Pick');
});

test('the editorial shelves are typed rows of stories the reader may see, newest first', function () {
    $admin = User::factory()->admin()->create();
    $old = collectionOf($admin, [Media::factory()->create(['title' => 'Old Pick'])], ['name' => 'Old Theme', 'is_editorial' => true, 'updated_at' => now()->subDays(9)]);
    $new = collectionOf($admin, [Media::factory()->create(['title' => 'New Pick']), matureStory()], ['name' => 'New Theme', 'is_editorial' => true, 'updated_at' => now()->subDay()]);

    $shelves = app(EditorialCollections::class)->shelves(User::factory()->create(), 5);

    expect($shelves->pluck('title')->all())->toBe(['New Theme', 'Old Theme'])
        ->and($shelves->first()->items->pluck('title')->all())->toBe(['New Pick'])
        ->and($shelves->first()->href)->toBe(route('collections.show', ['slug' => $new->slug, 'editors' => 1]))
        ->and(app(EditorialCollections::class)->shelves(User::factory()->create(), 1))->toHaveCount(1);
});

test('the library page has a Collections tab that is current on the collections pages', function () {
    $this->actingAs($user = User::factory()->create());
    $c = Collection::factory()->for($user)->create();

    foreach ([route('library.index'), route('collections.index'), route('collections.show', $c->slug)] as $url) {
        $html = $this->get($url)->getContent();

        expect(collect(htmlQuery($html, '//nav[@aria-label="Library sections"]//a'))->map(fn (DOMElement $link): string => trim($link->textContent))->all())->toContain('Collections');
    }

    $html = $this->get(route('collections.index'))->getContent();
    expect(trim(htmlQuery($html, '//nav[@aria-label="Library sections"]//a[@aria-current="page"]')->item(0)->textContent))->toBe('Collections')
        ->and(primaryNavs($html)['header'])->not->toContain('>Collections<'); // a tab under Library, not a bar item
});
