<?php

use App\Enums\PublicationStatus;
use App\Models\Media;
use App\Models\User;
use Livewire\Livewire;

test('admins can search media and list removed ones', function () {
    $kept = Media::factory()->create(['title' => 'Monster Kept']);
    $gone = Media::factory()->create(['title' => 'Monster Gone']);
    Media::factory()->create(['title' => 'Berserk']);
    $gone->delete();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.media.index')
        ->set('search', 'monster')
        ->assertSee('Monster Kept')->assertDontSee('Monster Gone')->assertDontSee('Berserk')
        ->set('trashed', true)
        ->assertSee('Monster Gone')->assertDontSee('Monster Kept');
});

test('a search term is matched literally', function () {
    Media::factory()->create(['title' => '100% Wolf']);
    Media::factory()->create(['title' => 'Other']);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.media.index')->set('search', '%')->assertSee('100% Wolf')->assertDontSee('Other');
});

test('editing a field locks it', function () {
    $media = Media::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.media.edit', ['media' => $media])->set('title', 'Fixed')->call('save');

    expect($media->fresh()->title)->toBe('Fixed')->and($media->fresh()->locked_fields)->toContain('title')
        ->and($media->fresh()->search_text)->toContain('fixed');
});

test('an unedited field is not locked', function () {
    $media = Media::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.media.edit', ['media' => $media])->set('title', 'Fixed')->call('save');

    expect($media->fresh()->locked_fields)->toBe(['title']);
});

test('saving an unchanged form locks nothing', function () {
    $media = Media::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.media.edit', ['media' => $media])->call('save');

    expect($media->fresh()->locked_fields)->toBe([]);
});

test('status and age rating save as enums and an emptied description becomes null', function () {
    $media = Media::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.media.edit', ['media' => $media])
        ->set('status', 'completed')->set('age_rating', 'mature')->set('description', '  ')->call('save')
        ->assertHasNoErrors();

    $media = $media->fresh();
    expect($media->status)->toBe(PublicationStatus::Completed)->and($media->description)->toBeNull()
        ->and($media->locked_fields)->toEqualCanonicalizing(['status', 'age_rating', 'description']);
});

test('invalid input is rejected without writing', function () {
    $media = Media::factory()->create(['title' => 'Keep']);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.media.edit', ['media' => $media])->set('title', '')->set('status', 'bogus')->call('save')
        ->assertHasErrors(['title', 'status']);

    expect($media->fresh()->title)->toBe('Keep')->and($media->fresh()->locked_fields)->toBe([]);
});

test('unlocking a field removes only that lock', function () {
    $media = Media::factory()->create(['locked_fields' => ['title', 'description']]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.media.edit', ['media' => $media])->call('unlock', 'title');

    expect($media->fresh()->locked_fields)->toBe(['description']);
});

test('removing an invalid record soft deletes it', function () {
    $media = Media::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.media.edit', ['media' => $media])->call('remove')->assertRedirect(route('admin.media.index'));

    expect($media->fresh()->trashed())->toBeTrue();
});

test('non admins cannot open the edit page or run its actions', function () {
    $media = Media::factory()->create(['locked_fields' => ['title']]);
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.media.edit', $media))->assertForbidden();
    Livewire::test('pages::admin.media.edit', ['media' => $media])->assertForbidden();
});

test('a removed record cannot be opened for editing', function () {
    $media = Media::factory()->create();
    $media->delete();

    $this->actingAs(User::factory()->admin()->create())->get(route('admin.media.edit', $media->id))->assertNotFound();
});
