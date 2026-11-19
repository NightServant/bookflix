<?php

use App\Enums\AgeRating;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Creator;
use App\Models\Media;
use App\Models\User;
use Livewire\Livewire;

/*
 * The admin Staff picks page: the collections admins make, each with a switch that shows it to every reader as a themed row, and an editor
 * (a flyout) to make and change them: name, description, the switch, and the stories in order.
 */

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Bookflix Admin']);
});

function staffPicks(array $query = [])
{
    return Livewire::withQueryParams($query)->test('pages::admin.staff-picks.index');
}

test('only an admin opens the page', function () {
    $this->get(route('admin.staff-picks'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('admin.staff-picks'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.staff-picks'))->assertOk()->assertSee('Staff picks');
});

test('it lists the collections admins made, shown or not, and never a reader\'s, with filters for each', function () {
    Collection::factory()->editorial()->create(['user_id' => $this->admin->id, 'name' => 'Quiet Sundays', 'updated_at' => now()]);
    Collection::factory()->create(['user_id' => $this->admin->id, 'name' => 'Draft Pick', 'updated_at' => now()->subDay()]);
    Collection::factory()->create(['name' => 'Diary Of Mine']);
    $this->actingAs($this->admin);

    expect(staffPicks()->instance()->collections->pluck('name')->all())->toBe(['Quiet Sundays', 'Draft Pick'])
        ->and(staffPicks(['show' => 'shown'])->instance()->collections->pluck('name')->all())->toBe(['Quiet Sundays'])
        ->and(staffPicks(['show' => 'off'])->instance()->collections->pluck('name')->all())->toBe(['Draft Pick']);

    staffPicks()->assertSee('Bookflix Admin')->assertDontSee('Diary Of Mine')->assertSee('2 staff picks · 1 shown to readers');
});

test('the switch shows a pick to readers or takes it off, without touching its updated date', function () {
    $pick = Collection::factory()->create(['user_id' => $this->admin->id, 'updated_at' => now()->subWeek()]);
    $this->actingAs($this->admin);

    staffPicks()->call('toggle', $pick->id)->assertOk();
    expect($pick->fresh()->is_editorial)->toBeTrue()->and($pick->fresh()->updated_at->toDateString())->toBe(now()->subWeek()->toDateString());

    staffPicks()->call('toggle', $pick->id);
    expect($pick->fresh()->is_editorial)->toBeFalse();
});

test('a reader collection can never be switched on or edited, and an unknown one is not found', function () {
    $private = Collection::factory()->create();
    $this->actingAs($this->admin);

    staffPicks()->call('toggle', $private->id)->assertNotFound();
    staffPicks()->call('edit', $private->id)->assertNotFound();
    staffPicks()->call('toggle', 999999)->assertNotFound();
    expect($private->fresh()->is_editorial)->toBeFalse();
});

test('the page re-authorizes on every call', function () {
    $pick = Collection::factory()->editorial()->create();
    $page = Livewire::actingAs($this->admin)->test('pages::admin.staff-picks.index');

    $this->admin->forceFill(['is_admin' => false])->save();
    $this->actingAs($this->admin->fresh());

    $page->call('toggle', $pick->id)->assertForbidden();
    expect($pick->fresh()->is_editorial)->toBeTrue();
});

test('an admin makes a new staff pick: name, description, the switch and stories found by title or author, in order', function () {
    $piranesi = Media::factory()->create(['title' => 'Piranesi']);
    $psalm = Media::factory()->create(['title' => 'A Psalm for the Wild-Built']);
    $psalm->creators()->attach(Creator::factory()->create(['name' => 'Becky Chambers']), ['role' => 'author', 'position' => 0]);
    Media::factory()->create(['title' => 'Piranesi Adult', 'age_rating' => AgeRating::Adult]);
    $this->actingAs($this->admin);

    $page = staffPicks()->call('create')
        ->assertSet('editing', true)
        ->set('name', 'Quiet Sundays')
        ->set('description', 'Slow, gentle reads')
        ->set('shown', true)
        ->set('search', 'pirane')
        ->assertSee('Piranesi')->assertDontSee('Piranesi Adult')
        ->call('addStory', $piranesi->id)
        ->set('search', 'chambers')
        ->assertSee('A Psalm for the Wild-Built')
        ->call('addStory', $psalm->id)
        ->call('moveStory', 1, -1)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editing', false);

    $pick = Collection::query()->where('name', 'Quiet Sundays')->sole();
    expect($pick->user_id)->toBe($this->admin->id)
        ->and($pick->is_editorial)->toBeTrue()
        ->and($pick->description)->toBe('Slow, gentle reads')
        ->and($pick->items()->orderBy('position')->pluck('media_id')->all())->toBe([$psalm->id, $piranesi->id]);

    $page->assertSee('Quiet Sundays');
});

test('editing loads the pick, and saving keeps only stories every reader may see', function () {
    $pick = Collection::factory()->editorial()->create(['name' => 'Old Name']);
    $first = Media::factory()->create(['title' => 'First Story']);
    $gated = Media::factory()->create(['title' => 'Gated Story', 'age_rating' => AgeRating::Adult]);
    CollectionItem::factory()->for($pick)->for($first, 'media')->create(['position' => 0]);
    CollectionItem::factory()->for($pick)->for($gated, 'media')->create(['position' => 1]);
    $this->actingAs($this->admin);

    staffPicks()->call('edit', $pick->id)
        ->assertSet('name', 'Old Name')
        ->assertSet('storyIds', [$first->id])
        ->set('name', 'New Name')
        ->call('removeStory', $first->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($pick->fresh()->name)->toBe('New Name')->and($pick->items()->count())->toBe(0);
});

test('a staff pick needs a name', function () {
    $this->actingAs($this->admin);

    staffPicks()->call('create')->set('name', '  ')->call('save')->assertHasErrors('name');
    expect(Collection::query()->count())->toBe(0);
});

test('deleting a staff pick removes it and its list, never the stories', function () {
    $pick = Collection::factory()->editorial()->create();
    $story = Media::factory()->create();
    CollectionItem::factory()->for($pick)->for($story, 'media')->create();
    $this->actingAs($this->admin);

    staffPicks()->call('edit', $pick->id)->call('delete')->assertSet('editing', false);

    expect(Collection::query()->find($pick->id))->toBeNull()->and($story->fresh())->not->toBeNull();
});

test('each switch is a named switch with its state, and each mobile cell names its column', function () {
    Collection::factory()->editorial()->create(['name' => 'Quiet Sundays']);
    $this->actingAs($this->admin);

    expect($this->get(route('admin.staff-picks'))->getContent())
        ->toContain('role="switch"')
        ->toContain('aria-checked="true"')
        ->toContain('aria-label="Shown to readers: Quiet Sundays"')
        ->toContain('data-label="Made by"')
        ->toContain('data-label="Shown to readers"');
});

test('the empty state says what to do', function () {
    $this->actingAs($this->admin);

    staffPicks()->assertSee('No staff picks yet')->assertSee('Make one with New staff pick');
});
