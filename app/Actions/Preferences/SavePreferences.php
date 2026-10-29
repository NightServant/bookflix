<?php

namespace App\Actions\Preferences;

use App\Access\MatureAccess;
use App\Models\Genre;
use App\Models\MediaType;
use App\Models\Theme;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;

/**
 * Save what a reader said they like. Each kind (formats, genres, themes) is replaced by the list given, or left alone when the list is null:
 * what is no longer ticked goes, what is new arrives with weight 1, and what stays keeps the weight it has (Phase 6 may have grown it). The lists are slugs the
 * caller has already checked against what was on offer (see PreferenceChoices::rules); a slug that matches nothing is ignored. A mature genre
 * is only ever added for a reader who may see it, and one saved while they could stays when they can no longer see it. Always the given
 * reader's own rows.
 */
class SavePreferences
{
    /**
     * @param  list<string>|null  $mediaTypes
     * @param  list<string>|null  $genres
     * @param  list<string>|null  $themes
     */
    public function handle(User $user, ?array $mediaTypes = null, ?array $genres = null, ?array $themes = null): void
    {
        $mature = MatureAccess::allows($user);

        DB::transaction(function () use ($user, $mediaTypes, $genres, $themes, $mature): void {
            $this->sync($user, 'media_type', $mediaTypes === null ? null : array_values(MediaType::query()->whereIn('slug', $mediaTypes)->pluck('id')->all()));

            $this->sync(
                $user,
                'genre',
                $genres === null ? null : array_values(Genre::query()->visible($mature)->whereIn('slug', $genres)->pluck('id')->all()),
                $mature ? [] : array_values(Genre::query()->where('is_mature', true)->pluck('id')->all()),
            );

            $this->sync($user, 'theme', $themes === null ? null : array_values(Theme::query()->whereIn('slug', $themes)->pluck('id')->all()));
        });
    }

    /**
     * @param  list<int>|null  $ids  the new list for this kind, or null to leave it
     * @param  list<int>  $keep  ids that stay whatever the list says
     */
    private function sync(User $user, string $kind, ?array $ids, array $keep = []): void
    {
        if ($ids === null) {
            return;
        }

        $user->preferences()->where('preferable_type', $kind)->whereNotIn('preferable_id', [...$ids, ...$keep])->delete();

        UserPreference::query()->insertOrIgnore(array_map(fn (int $id): array => [
            'user_id' => $user->id,
            'preferable_type' => $kind,
            'preferable_id' => $id,
            'weight' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $ids));
    }
}
