<?php

namespace App\Actions\Library;

use App\Enums\Visibility;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Start a collection for a reader. The slug is the name's, cut to 100 characters (a slug can come out longer than the name: a euro sign is three
 * letters), and made unique among that reader's own collections with a counter ("favorites", "favorites-2"); a name with no letters in it becomes
 * "collection". A reader keeps at most `bookflix.collections.max_per_reader` of them, counted with their row locked so two quick requests cannot
 * both take the last place. The caller has validated the name and decides who the reader is.
 *
 * @throws ValidationException when the reader already has as many collections as they may keep
 */
class CreateCollection
{
    public function handle(User $user, string $name, ?string $description = null, Visibility $visibility = Visibility::Private): Collection
    {
        return DB::transaction(function () use ($user, $name, $description, $visibility): Collection {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $limit = (int) config('bookflix.collections.max_per_reader');

            if ($user->collections()->count() >= $limit) {
                throw ValidationException::withMessages(['name' => trans_choice('You can keep at most :count collection. Delete one to make room for another.|You can keep at most :count collections. Delete one to make room for another.', $limit)]);
            }

            $base = rtrim(Str::limit(Str::slug($name), 100, ''), '-') ?: 'collection';
            $taken = $user->collections()->where('slug', 'like', $base.'%')->pluck('slug')->all();

            for ($counter = 1; ; $counter++) {
                $slug = $counter === 1 ? $base : "{$base}-{$counter}";

                if (in_array($slug, $taken, true)) {
                    continue;
                }

                try {
                    // Its own savepoint, so a slug that was taken meanwhile rolls back to it and the next one is tried.
                    return DB::transaction(fn (): Collection => $user->collections()->create([
                        'name' => $name,
                        'slug' => $slug,
                        'description' => $description,
                        'visibility' => $visibility,
                    ]));
                } catch (UniqueConstraintViolationException) {
                    $taken[] = $slug;
                }
            }
        });
    }
}
