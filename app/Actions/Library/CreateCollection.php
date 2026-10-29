<?php

namespace App\Actions\Library;

use App\Enums\Visibility;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Start a collection for a reader. The slug is the name's, made unique among that reader's own collections with a counter ("favorites",
 * "favorites-2"); a name with no letters in it becomes "collection". The caller has validated the name and decides who the reader is.
 */
class CreateCollection
{
    public function handle(User $user, string $name, ?string $description = null, Visibility $visibility = Visibility::Private): Collection
    {
        $base = Str::slug($name) ?: 'collection';
        $taken = $user->collections()->where('slug', 'like', $base.'%')->pluck('slug')->all();

        for ($counter = 1; ; $counter++) {
            $slug = $counter === 1 ? $base : "{$base}-{$counter}";

            if (in_array($slug, $taken, true)) {
                continue;
            }

            try {
                // Its own transaction, so a slug that two quick requests raced for rolls back to a savepoint and the loser tries the next one.
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
    }
}
