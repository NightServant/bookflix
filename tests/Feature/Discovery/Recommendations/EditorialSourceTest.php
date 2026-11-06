<?php

use App\Discovery\Recommendations\Sources\EditorialSource;
use App\Enums\ReasonType;
use App\Models\Collection as StoryCollection;
use App\Models\CollectionItem;
use App\Models\Media;
use App\Models\User;

function pick(StoryCollection $collection, Media $media, int $position): void
{
    CollectionItem::create(['collection_id' => $collection->id, 'media_id' => $media->id, 'position' => $position]);
}

test('editorial collection items come in position order and private collections never leak', function () {
    $user = User::factory()->create();
    [$first, $second, $private] = Media::factory()->count(3)->create();
    $editorial = StoryCollection::factory()->editorial()->public()->create();
    pick($editorial, $second, 2);
    pick($editorial, $first, 1);
    pick(StoryCollection::factory()->create(['user_id' => $user->id]), $private, 1);
    pick(StoryCollection::factory()->public()->create(), $private, 1);

    $c = app(EditorialSource::class)->candidatesFor($user, 10);

    expect($c->pluck('mediaId')->all())->toBe([$first->id, $second->id])
        ->and($c->first()->score)->toBe(1.0)
        ->and($c->first()->reason)->toBe(ReasonType::Editorial)
        ->and($c->first()->source)->toBe('editorial');
});

test('a story in two collections appears once and the limit is honoured', function () {
    $user = User::factory()->create();
    $media = Media::factory()->count(3)->create();
    [$a, $b] = StoryCollection::factory()->editorial()->count(2)->create();
    pick($a, $media[0], 1);
    pick($b, $media[0], 1);
    pick($a, $media[1], 2);
    pick($a, $media[2], 3);

    expect(app(EditorialSource::class)->candidatesFor($user, 10))->toHaveCount(3)
        ->and(app(EditorialSource::class)->candidatesFor($user, 2))->toHaveCount(2);
});

test('library, dismissed, deleted and merged media are excluded', function () {
    $user = User::factory()->create();
    [$inLib, $dismissed, $deleted, $merged, $ok] = Media::factory()->count(5)->create();
    $editorial = StoryCollection::factory()->editorial()->create();
    foreach ([$inLib, $dismissed, $deleted, $merged, $ok] as $i => $media) {
        pick($editorial, $media, $i);
    }
    libraryEntry($user, $inLib, 'reading');
    dismissed($user, $dismissed);
    $deleted->delete();
    $merged->update(['merged_into_id' => $ok->id]);

    expect(app(EditorialSource::class)->candidatesFor($user, 10)->pluck('mediaId')->all())->toBe([$ok->id]);
});

test('a mature pick is left out until the reader opts in', function () {
    $mature = matureStory();
    pick(StoryCollection::factory()->editorial()->create(), $mature, 1);

    expect(app(EditorialSource::class)->candidatesFor(User::factory()->create(), 10))->toBeEmpty()
        ->and(app(EditorialSource::class)->candidatesFor(User::factory()->showsMature()->create(), 10)->pluck('mediaId')->all())->toBe([$mature->id]);
});
