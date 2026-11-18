<?php

use App\Actions\Social\CloneCollection;
use App\Enums\Visibility;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Media;
use App\Models\User;
use App\Social\CollectionLookup;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

function publicCollectionWith(array $media, array $attributes = []): Collection
{
    $collection = Collection::factory()->public()->create($attributes + ['name' => 'Cozy reads', 'description' => 'Warm ones']);

    foreach ($media as $position => $story) {
        CollectionItem::factory()->create(['collection_id' => $collection->id, 'media_id' => $story->id, 'position' => $position, 'note' => "Private note {$position}"]);
    }

    return $collection;
}

test('a copy is private, owned by the reader, with items in order and no notes', function () {
    [$a, $b, $c] = [Media::factory()->create(), Media::factory()->create(), Media::factory()->create()];
    $source = publicCollectionWith([$c, $a, $b]);
    $reader = User::factory()->create();

    $copy = app(CloneCollection::class)->handle($reader, $source);

    expect($copy->user_id)->toBe($reader->id)
        ->and($copy->visibility)->toBe(Visibility::Private)
        ->and($copy->is_editorial)->toBeFalse()
        ->and($copy->name)->toBe('Cozy reads')
        ->and($copy->description)->toBe('Warm ones')
        ->and($copy->items()->orderBy('position')->pluck('media_id')->all())->toBe([$c->id, $a->id, $b->id])
        ->and($copy->items()->orderBy('position')->pluck('position')->all())->toBe([0, 1, 2])
        ->and($copy->items()->whereNotNull('note')->count())->toBe(0)
        ->and($source->items()->count())->toBe(3);
});

test('the slug is made unique among the readers own collections', function () {
    $source = publicCollectionWith([]);
    $reader = User::factory()->create();

    $first = app(CloneCollection::class)->handle($reader, $source);
    $second = app(CloneCollection::class)->handle($reader, $source);

    expect($first->slug)->toBe('cozy-reads')->and($second->slug)->toBe('cozy-reads-2')->and($second->name)->toBe('Cozy reads');
});

test('stories behind the mature gate are skipped for a reader who has not opted in', function () {
    $plain = Media::factory()->create();
    $source = publicCollectionWith([matureStory(), $plain]);

    $copy = app(CloneCollection::class)->handle(User::factory()->create(), $source);

    expect($copy->items()->pluck('media_id')->all())->toBe([$plain->id])->and($copy->items()->first()->position)->toBe(0);
});

test('refused: a private, moderator-hidden, own, deactivated-owner or admin-owned collection, and an admin or deactivated reader', function (Closure $make) {
    $reader = User::factory()->create();
    [$actor, $source] = $make($reader);

    expect(fn () => app(CloneCollection::class)->handle($actor, $source))->toThrow(ValidationException::class);
    expect(Collection::query()->where('user_id', $actor->id)->count())->toBe($source->user_id === $actor->id ? 1 : 0);
})->with([
    'private' => [fn (User $reader) => [$reader, Collection::factory()->create()]],
    'hidden' => [fn (User $reader) => [$reader, Collection::factory()->public()->create(['moderation_hidden_at' => now()])]],
    'own' => [fn (User $reader) => [$reader, Collection::factory()->public()->create(['user_id' => $reader->id])]],
    'deactivated owner' => [fn (User $reader) => [$reader, Collection::factory()->public()->create(['user_id' => User::factory()->deactivated()])]],
    'admin owner' => [fn (User $reader) => [$reader, Collection::factory()->public()->create(['user_id' => User::factory()->admin()])]],
    'admin reader' => [fn (User $reader) => [User::factory()->admin()->create(), Collection::factory()->public()->create()]],
    'deactivated reader' => [fn (User $reader) => [User::factory()->deactivated()->create(), Collection::factory()->public()->create()]],
]);

test('an editorial collection may be copied', function () {
    $copy = app(CloneCollection::class)->handle(User::factory()->create(), Collection::factory()->editorial()->create());

    expect($copy->visibility)->toBe(Visibility::Private)->and($copy->is_editorial)->toBeFalse();
});

test('copies are rate limited per reader', function () {
    config(['bookflix.social.collection_clones_per_hour' => 2]);
    RateLimiter::clear('collection-clones:1');
    $source = publicCollectionWith([]);
    $reader = User::factory()->create();

    app(CloneCollection::class)->handle($reader, $source);
    app(CloneCollection::class)->handle($reader, $source);

    expect(fn () => app(CloneCollection::class)->handle($reader, $source))->toThrow(ValidationException::class);
});

test('a public collection resolves by owner handle and slug, even when the profile is private', function () {
    $owner = User::factory()->create();
    $owner->profile()->updateOrCreate([], ['handle' => 'ada', 'profile_visibility' => Visibility::Private]);
    $collection = Collection::factory()->public()->create(['user_id' => $owner->id, 'name' => 'Favorites']);
    $viewer = User::factory()->create();

    $lookup = app(CollectionLookup::class);

    expect($lookup->find($viewer, 'ADA', 'favorites')?->is($collection))->toBeTrue()
        ->and($lookup->find($viewer, 'ada', 'other'))->toBeNull()
        ->and($lookup->find($viewer, 'nobody', 'favorites'))->toBeNull();

    $collection->forceFill(['visibility' => Visibility::Private])->save();

    expect($lookup->find($viewer, 'ada', 'favorites'))->toBeNull()
        ->and($lookup->find($owner, 'ada', 'favorites')?->is($collection))->toBeTrue();
});

test('the lookup does not mix up two readers public collections of the same name', function () {
    $mine = Collection::factory()->public()->create(['name' => 'Favorites']);
    $mine->user->profile()->updateOrCreate([], ['handle' => 'one']);
    $theirs = Collection::factory()->public()->create(['name' => 'Favorites']);
    $theirs->user->profile()->updateOrCreate([], ['handle' => 'two']);

    expect(app(CollectionLookup::class)->find(User::factory()->create(), 'two', 'favorites')?->is($theirs))->toBeTrue();
});
