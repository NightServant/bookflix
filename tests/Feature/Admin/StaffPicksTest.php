<?php

use App\Discovery\EditorialCollections;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Media;
use App\Models\User;
use Livewire\Livewire;

/*
 * The admin Staff picks page: public collections readers built, each with a switch that makes it an editorial row for every reader.
 */

test('only an admin opens the page', function () {
    $this->get(route('admin.staff-picks'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('admin.staff-picks'))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.staff-picks'))->assertOk()->assertSee('Staff picks');
});

test('it lists public collections and picks, most recently changed first, and never a private collection or a deactivated reader\'s', function () {
    $public = Collection::factory()->public()->create(['name' => 'Quiet Sundays', 'updated_at' => now()]);
    $pick = Collection::factory()->public()->editorial()->create(['name' => 'Sword and Sorrow', 'updated_at' => now()->subWeek()]);
    $oldPick = Collection::factory()->editorial()->create(['name' => 'Old Private Pick', 'updated_at' => now()->subMonth()]);
    Collection::factory()->create(['name' => 'Diary Of Mine']);
    Collection::factory()->public()->for(User::factory()->deactivated())->create(['name' => 'Gone Reader List']);
    CollectionItem::factory()->count(3)->for($public)->create();

    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test('pages::admin.staff-picks.index');

    expect($page->instance()->collections->pluck('name')->all())->toBe(['Quiet Sundays', 'Sword and Sorrow', 'Old Private Pick']);
    $page->assertSee($public->user->name)->assertSee('Shown to readers')->assertDontSee('Diary Of Mine')->assertDontSee('Gone Reader List')
        ->assertDontSee($public->user->email)->assertSee('3 collections')->assertSee('2 staff picks');

    $page->set('show', 'picks');
    expect($page->instance()->collections->pluck('name')->all())->toBe(['Sword and Sorrow', 'Old Private Pick']);
});

test('turning a public collection on makes it a staff pick readers see, without touching its updated date', function () {
    $collection = Collection::factory()->public()->create(['updated_at' => now()->subMonth()]);
    CollectionItem::factory()->for($collection)->create();
    $before = $collection->fresh()->updated_at;

    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test('pages::admin.staff-picks.index');
    $order = $page->instance()->collections->modelKeys();
    $page->call('toggle', $collection->id)->assertOk();

    // The row stays where it was: a switch never moves it away from under the pointer.
    expect($page->instance()->collections->modelKeys())->toBe($order);

    expect($collection->fresh()->is_editorial)->toBeTrue()
        ->and($collection->fresh()->updated_at->equalTo($before))->toBeTrue()
        ->and(app(EditorialCollections::class)->collections(User::factory()->create())->modelKeys())->toContain($collection->id);

    Livewire::test('pages::admin.staff-picks.index')->call('toggle', $collection->id);
    expect($collection->fresh()->is_editorial)->toBeFalse();
});

test('a private collection can be turned off but never on, and an unknown one is not found', function () {
    $private = Collection::factory()->create();
    $oldPick = Collection::factory()->editorial()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.staff-picks.index')->call('toggle', $private->id)->assertNotFound();
    expect($private->fresh()->is_editorial)->toBeFalse();

    Livewire::test('pages::admin.staff-picks.index')->call('toggle', $oldPick->id)->assertOk();
    expect($oldPick->fresh()->is_editorial)->toBeFalse();

    Livewire::test('pages::admin.staff-picks.index')->call('toggle', $oldPick->id)->assertNotFound();
    Livewire::test('pages::admin.staff-picks.index')->call('toggle', 999999)->assertNotFound();
});

test('the switch re-authorizes on every call', function () {
    $collection = Collection::factory()->public()->create();
    $admin = User::factory()->admin()->create();
    $page = Livewire::actingAs($admin)->test('pages::admin.staff-picks.index');

    $admin->forceFill(['is_admin' => false])->save();
    $this->actingAs($admin->fresh());

    $page->call('toggle', $collection->id)->assertForbidden();
    expect($collection->fresh()->is_editorial)->toBeFalse();
});

test('a pick ends when its owner makes the collection private, and only then', function () {
    $collection = Collection::factory()->public()->editorial()->create();

    $collection->update(['name' => 'Renamed']);
    expect($collection->fresh()->is_editorial)->toBeTrue();

    Livewire::actingAs($collection->user)->test('pages::collections.show', ['slug' => $collection->slug])
        ->set('visibility', 'private')->call('save')->assertHasNoErrors();

    expect($collection->fresh()->is_editorial)->toBeFalse();
});

test('each switch is a named switch with its state, and each mobile cell names its column', function () {
    Collection::factory()->public()->editorial()->create(['name' => 'Quiet Sundays']);
    Collection::factory()->public()->create(['name' => 'Rainy Day Poetry']);
    $this->actingAs(User::factory()->admin()->create());

    expect($this->get(route('admin.staff-picks'))->getContent())
        ->toContain('role="switch"')
        ->toContain('aria-checked="true"')
        ->toContain('aria-checked="false"')
        ->toContain('aria-label="Staff pick: Quiet Sundays"')
        ->toContain('aria-label="Staff pick: Rainy Day Poetry"')
        ->toContain('data-label="Owner"')
        ->toContain('data-label="Staff pick"');
});

test('the empty states say what to do', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.staff-picks.index')->assertSee('No public collections yet')
        ->set('show', 'picks')->assertSee('No staff picks yet');
});

test('story counts and names never read a story readers could not open: only the count is shown', function () {
    $collection = Collection::factory()->public()->create();
    CollectionItem::factory()->for($collection)->for(Media::factory()->create(['title' => 'Hidden Title Here']), 'media')->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.staff-picks.index')->assertDontSee('Hidden Title Here');
});
