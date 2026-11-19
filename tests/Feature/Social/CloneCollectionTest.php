<?php

use App\Actions\Social\CloneCollection;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

function editorialCollectionWith(array $media, array $attributes = []): Collection
{
    $collection = Collection::factory()->editorial()->for(User::factory()->admin())->create($attributes + ['name' => 'Cozy reads', 'description' => 'Warm ones']);

    foreach ($media as $position => $story) {
        CollectionItem::factory()->create(['collection_id' => $collection->id, 'media_id' => $story->id, 'position' => $position, 'note' => "Private note {$position}"]);
    }

    return $collection;
}

test('a copy is private, owned by the reader, with items in order and no notes', function () {
    [$a, $b, $c] = [Media::factory()->create(), Media::factory()->create(), Media::factory()->create()];
    $source = editorialCollectionWith([$c, $a, $b]);
    $reader = User::factory()->create();

    $copy = app(CloneCollection::class)->handle($reader, $source);

    expect($copy->user_id)->toBe($reader->id)
        ->and($copy->is_editorial)->toBeFalse()
        ->and($copy->name)->toBe('Cozy reads')
        ->and($copy->description)->toBe('Warm ones')
        ->and($copy->items()->orderBy('position')->pluck('media_id')->all())->toBe([$c->id, $a->id, $b->id])
        ->and($copy->items()->orderBy('position')->pluck('position')->all())->toBe([0, 1, 2])
        ->and($copy->items()->whereNotNull('note')->count())->toBe(0)
        ->and($source->items()->count())->toBe(3);
});

test('the slug is made unique among the readers own collections', function () {
    $source = editorialCollectionWith([]);
    $reader = User::factory()->create();

    $first = app(CloneCollection::class)->handle($reader, $source);
    $second = app(CloneCollection::class)->handle($reader, $source);

    expect($first->slug)->toBe('cozy-reads')->and($second->slug)->toBe('cozy-reads-2')->and($second->name)->toBe('Cozy reads');
});

test('stories behind the mature gate are skipped for a reader who has not opted in', function () {
    $plain = Media::factory()->create();
    $source = editorialCollectionWith([matureStory(), $plain]);

    $copy = app(CloneCollection::class)->handle(User::factory()->create(), $source);

    expect($copy->items()->pluck('media_id')->all())->toBe([$plain->id])->and($copy->items()->first()->position)->toBe(0);
});

test('refused: a reader collection, an own one, an editorial one of a deactivated owner, and an admin or deactivated reader', function (Closure $make) {
    $reader = User::factory()->create();
    [$actor, $source] = $make($reader);

    expect(fn () => app(CloneCollection::class)->handle($actor, $source))->toThrow(ValidationException::class);
    expect(Collection::query()->where('user_id', $actor->id)->count())->toBe($source->user_id === $actor->id ? 1 : 0);
})->with([
    'a reader collection' => [fn (User $reader) => [$reader, Collection::factory()->create()]],
    'an editorial one of a deactivated owner' => [fn (User $reader) => [$reader, Collection::factory()->create(['user_id' => User::factory()->deactivated(), 'is_editorial' => true])]],
    'own' => [fn (User $reader) => [$reader, Collection::factory()->editorial()->create(['user_id' => $reader->id])]],
    'admin reader' => [fn (User $reader) => [User::factory()->admin()->create(), Collection::factory()->editorial()->create()]],
    'deactivated reader' => [fn (User $reader) => [User::factory()->deactivated()->create(), Collection::factory()->editorial()->create()]],
]);

test('an editorial collection may be copied, and the copy is a private collection of the reader', function () {
    $source = Collection::factory()->editorial()->create();
    $reader = User::factory()->create();
    $copy = app(CloneCollection::class)->handle($reader, $source);

    expect($copy->is_editorial)->toBeFalse()
        ->and($copy->user_id)->toBe($reader->id)
        ->and(Gate::forUser(User::factory()->create())->allows('view', $copy))->toBeFalse();
});

test('copies are rate limited per reader', function () {
    config(['bookflix.social.collection_clones_per_hour' => 2]);
    RateLimiter::clear('collection-clones:1');
    $source = editorialCollectionWith([]);
    $reader = User::factory()->create();

    app(CloneCollection::class)->handle($reader, $source);
    app(CloneCollection::class)->handle($reader, $source);

    expect(fn () => app(CloneCollection::class)->handle($reader, $source))->toThrow(ValidationException::class);
});
