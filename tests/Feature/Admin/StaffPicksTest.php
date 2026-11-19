<?php

use App\Discovery\EditorialCollections;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Media;
use App\Models\User;
use Livewire\Livewire;

/*
 * The admin Staff picks page: the editorial collections, each with a switch that shows it to every reader as a themed row.
 */

test('only an admin opens the page', function () {
    $this->get(route('admin.staff-picks'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('admin.staff-picks'))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.staff-picks'))->assertOk()->assertSee('Staff picks');
});

test('it lists editorial collections only, most recently changed first, and never a reader\'s collection', function () {
    $admin = User::factory()->admin()->create();
    $new = Collection::factory()->editorial()->create(['user_id' => $admin->id, 'name' => 'Quiet Sundays', 'updated_at' => now()]);
    Collection::factory()->editorial()->create(['user_id' => $admin->id, 'name' => 'Sword and Sorrow', 'updated_at' => now()->subWeek()]);
    Collection::factory()->create(['name' => 'Diary Of Mine']);
    CollectionItem::factory()->count(3)->for($new)->create();

    $this->actingAs($admin);
    $page = Livewire::test('pages::admin.staff-picks.index');

    expect($page->instance()->collections->pluck('name')->all())->toBe(['Quiet Sundays', 'Sword and Sorrow']);
    $page->assertSee($admin->name)->assertSee('Shown to readers')->assertDontSee('Diary Of Mine')->assertDontSee($admin->email)->assertSee('2 staff picks')
        ->assertDontSee('public');
});

test('switching a staff pick off keeps its row until the page is reloaded, so it can be switched back on, without touching its updated date', function () {
    $collection = Collection::factory()->editorial()->create(['updated_at' => now()->subMonth()]);
    CollectionItem::factory()->for($collection)->create();
    $before = $collection->fresh()->updated_at;

    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test('pages::admin.staff-picks.index');
    $order = $page->instance()->collections->modelKeys();
    $page->call('toggle', $collection->id)->assertOk();

    // The row stays where it was: a switch never moves it away from under the pointer.
    expect($page->instance()->collections->modelKeys())->toBe($order)
        ->and($collection->fresh()->is_editorial)->toBeFalse()
        ->and($collection->fresh()->updated_at->equalTo($before))->toBeTrue()
        ->and(app(EditorialCollections::class)->collections(User::factory()->create())->modelKeys())->not->toContain($collection->id);

    $page->call('toggle', $collection->id)->assertOk();
    expect($collection->fresh()->is_editorial)->toBeTrue()
        ->and(app(EditorialCollections::class)->collections(User::factory()->create())->modelKeys())->toContain($collection->id);

    // A fresh page lists only what is on.
    $collection->forceFill(['is_editorial' => false])->save();
    expect(Livewire::test('pages::admin.staff-picks.index')->instance()->collections->modelKeys())->toBe([]);
});

test('a reader collection can never be switched on, and an unknown one is not found', function () {
    $private = Collection::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.staff-picks.index')->call('toggle', $private->id)->assertNotFound();
    Livewire::test('pages::admin.staff-picks.index')->call('toggle', 999999)->assertNotFound();
    expect($private->fresh()->is_editorial)->toBeFalse();
});

test('the switch re-authorizes on every call', function () {
    $collection = Collection::factory()->editorial()->create();
    $admin = User::factory()->admin()->create();
    $page = Livewire::actingAs($admin)->test('pages::admin.staff-picks.index');

    $admin->forceFill(['is_admin' => false])->save();
    $this->actingAs($admin->fresh());

    $page->call('toggle', $collection->id)->assertForbidden();
    expect($collection->fresh()->is_editorial)->toBeTrue();
});

test('each switch is a named switch with its state, and each mobile cell names its column', function () {
    Collection::factory()->editorial()->create(['name' => 'Quiet Sundays']);
    $this->actingAs(User::factory()->admin()->create());

    expect($this->get(route('admin.staff-picks'))->getContent())
        ->toContain('role="switch"')
        ->toContain('aria-checked="true"')
        ->toContain('aria-label="Staff pick: Quiet Sundays"')
        ->toContain('data-label="Owner"')
        ->toContain('data-label="Staff pick"');
});

test('the empty state says what to expect', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.staff-picks.index')->assertSee('No staff picks yet')->assertSee('Editorial collections made by admins appear here.');
});

test('story counts and names never read a story readers could not open: only the count is shown', function () {
    $collection = Collection::factory()->editorial()->create();
    CollectionItem::factory()->for($collection)->for(Media::factory()->create(['title' => 'Hidden Title Here']), 'media')->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.staff-picks.index')->assertDontSee('Hidden Title Here');
});
