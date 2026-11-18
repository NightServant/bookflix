<?php

namespace App\Actions\Social;

use App\Actions\Library\CreateCollection;
use App\Enums\Visibility;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * "Save a copy" of a collection (P10-R12): a private collection owned by the reader, with the source's name (the slug made unique among the reader's own
 * collections, as CreateCollection does) and description, and its stories in the same order, without the owner's notes. Only the stories the reader may
 * see are copied (Media::visibleTo), so a copy never carries a gated title. Refused as a ValidationException: an admin or deactivated reader, a reader who
 * may not view the collection, the reader's own collection, a reader with as many collections as they may keep (CreateCollection) and more than 10
 * copies an hour (the `collection-clones` limiter).
 */
class CloneCollection
{
    public function __construct(private readonly CreateCollection $create) {}

    public function handle(User $reader, Collection $source): Collection
    {
        if ($reader->is_admin || $reader->isDeactivated() || $reader->id === $source->user_id || ! $reader->can('view', $source)) {
            throw ValidationException::withMessages(['collection' => __('You can’t save a copy of this collection.')]);
        }

        $limit = RateLimiter::limiter('collection-clones')($reader);
        $key = 'collection-clones:'.$limit->key;

        if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            throw ValidationException::withMessages(['collection' => __('You are saving copies too fast. Try again later.')]);
        }

        RateLimiter::hit($key, $limit->decaySeconds);

        return DB::transaction(function () use ($reader, $source): Collection {
            $copy = $this->create->handle($reader, $source->name, $source->description, Visibility::Private);

            $mediaIds = CollectionItem::query()
                ->where('collection_id', $source->id)
                ->visibleTo($reader)
                ->orderBy('position')->orderBy('id')
                ->pluck('media_id');

            $now = now();

            $copy->items()->insert($mediaIds->values()->map(fn (int $mediaId, int $position): array => [
                'collection_id' => $copy->id,
                'media_id' => $mediaId,
                'position' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            return $copy;
        });
    }
}
