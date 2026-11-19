<?php

use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\User;
use Livewire\Livewire;

/*
 * A staff pick's page as a reader sees it: who picked it, and Save a copy and Copy link.
 */

beforeEach(function () {
    $this->pick = Collection::factory()->editorial()->create(['name' => 'Quiet Sundays', 'slug' => 'quiet-sundays']);
    CollectionItem::factory()->count(2)->for($this->pick)->create();
    $this->reader = User::factory()->create();
});

test('a staff pick names its editors and offers Save a copy and Copy link', function () {
    $html = $this->actingAs($this->reader)->get(route('collections.show', ['slug' => 'quiet-sundays', 'editors' => 1]))->assertOk()
        ->assertSee('Picked by the Bookflix editors · 2 stories')
        ->getContent();

    expect(htmlQuery($html, '//button[normalize-space()="Save a copy"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//button[normalize-space()="Copy link"]')->length)->toBe(1);
});

test('saving a copy makes a private collection of the reader\'s and links to it', function () {
    $this->actingAs($this->reader);

    Livewire::withQueryParams(['editors' => 1])->test('pages::collections.show', ['slug' => 'quiet-sundays'])
        ->call('saveCopy')
        ->assertHasNoErrors()
        ->assertSee('Saved to your collections as a private copy.');

    $copy = $this->reader->collections()->sole();
    expect($copy->name)->toBe('Quiet Sundays')->and($copy->is_editorial)->toBeFalse()->and($copy->items()->count())->toBe(2);
});

test('a reader\'s own collection has neither button', function () {
    $mine = Collection::factory()->create(['user_id' => $this->reader->id, 'slug' => 'mine']);

    $html = $this->actingAs($this->reader)->get(route('collections.show', 'mine'))->assertOk()->getContent();

    expect(htmlQuery($html, '//button[normalize-space()="Save a copy"]')->length)->toBe(0)
        ->and(htmlQuery($html, '//button[normalize-space()="Copy link"]')->length)->toBe(0);
});
