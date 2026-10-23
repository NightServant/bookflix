<?php

use App\Enums\DuplicateStatus;
use App\Models\Creator;
use App\Models\DuplicateCandidate;
use App\Models\Media;
use App\Models\MediaExternalId;
use App\Models\User;
use Livewire\Livewire;

test('pending candidates show side by side with their score', function () {
    $candidate = DuplicateCandidate::factory()->create(['score' => 0.82]);
    $candidate->media->creators()->attach(Creator::factory()->create(['name' => 'Naoki Urasawa']), ['role' => 'author', 'position' => 0]);
    DuplicateCandidate::factory()->create(['status' => DuplicateStatus::Dismissed, 'media_id' => Media::factory()->create(['title' => 'Hidden Pair'])]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.duplicates.index')
        ->assertSee($candidate->media->title)->assertSee($candidate->duplicateMedia->title)
        ->assertSee('82% similar')->assertSee('Naoki Urasawa')->assertDontSee('Hidden Pair');
});

test('merging a duplicate candidate moves ids and the loser slug resolves to the winner', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    $loser->externalIds()->save(MediaExternalId::factory()->make());
    $candidate = DuplicateCandidate::factory()->create(['media_id' => $winner->id, 'duplicate_media_id' => $loser->id, 'score' => 0.82]);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test('pages::admin.duplicates.index')->call('merge', $candidate->id);

    expect($winner->externalIds()->count())->toBe(1)
        ->and($candidate->fresh()->status)->toBe(DuplicateStatus::Merged)
        ->and($candidate->fresh()->resolved_by)->toBe($admin->id)
        ->and($loser->fresh()->trashed())->toBeTrue()
        ->and(Media::resolveSlug($loser->slug)?->is($winner))->toBeTrue();
});

test('the admin picks which side wins', function () {
    $candidate = DuplicateCandidate::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.duplicates.index')->call('merge', $candidate->id, $candidate->duplicate_media_id);

    $first = Media::withTrashed()->find($candidate->media_id);

    expect($first->trashed())->toBeTrue()
        ->and($first->merged_into_id)->toBe($candidate->duplicate_media_id)
        ->and(Media::find($candidate->duplicate_media_id))->not->toBeNull();
});

test('a winner outside the pair is rejected', function () {
    $candidate = DuplicateCandidate::factory()->create();
    $outsider = Media::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.duplicates.index')->call('merge', $candidate->id, $outsider->id)->assertStatus(422);

    expect($candidate->fresh()->status)->toBe(DuplicateStatus::Pending)->and(Media::count())->toBe(3);
});

test('a resolved candidate or one with a removed record cannot be merged', function () {
    $resolved = DuplicateCandidate::factory()->create(['status' => DuplicateStatus::Dismissed]);
    $removed = DuplicateCandidate::factory()->create();
    $removed->media->delete();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.duplicates.index')->call('merge', $resolved->id)->assertNotFound();
    Livewire::test('pages::admin.duplicates.index')->call('merge', $removed->id)->assertNotFound();
});

test('dismissing a candidate keeps both records', function () {
    $c = DuplicateCandidate::factory()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test('pages::admin.duplicates.index')->call('dismiss', $c->id);

    expect($c->fresh()->status)->toBe(DuplicateStatus::Dismissed)->and($c->fresh()->resolved_by)->toBe($admin->id)->and(Media::count())->toBe(2);
});

test('duplicate actions re-authorize on every call', function () {
    $c = DuplicateCandidate::factory()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $merge = Livewire::test('pages::admin.duplicates.index');
    $dismiss = Livewire::test('pages::admin.duplicates.index');

    $admin->forceFill(['is_admin' => false])->save();

    $merge->call('merge', $c->id)->assertForbidden();
    $dismiss->call('dismiss', $c->id)->assertForbidden();

    expect($c->fresh()->status)->toBe(DuplicateStatus::Pending)->and(Media::withTrashed()->count())->toBe(2);
});

test('a merged away slug answers 301 to the winner and unknown slugs 404', function () {
    $candidate = DuplicateCandidate::factory()->create();
    [$winner, $loser] = [$candidate->media, $candidate->duplicateMedia];
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test('pages::admin.duplicates.index')->call('merge', $candidate->id);

    $this->get(route('stories.show', $loser->slug))
        ->assertStatus(301)
        ->assertRedirect(route('stories.show', $winner->slug));
    $this->get(route('stories.show', 'no-such-story'))->assertNotFound();
});
