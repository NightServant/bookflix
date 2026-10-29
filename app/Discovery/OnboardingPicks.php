<?php

namespace App\Discovery;

use App\Models\Media;
use App\Models\MediaType;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * The stories onboarding offers on its last step ("which of these have you read?"): the most popular ones with a cover, those that match the
 * formats and genres the reader chose first (both, when both were chosen), then the most popular of the rest until there are enough to choose
 * from. A story behind the mature gate is offered only to a reader past it (Media::visibleTo), and a story already in the reader's library is
 * not offered again.
 */
class OnboardingPicks
{
    /** How many stories are offered, and the most a reader can pick. */
    public const LIMIT = 24;

    /**
     * @param  list<string>  $typeSlugs
     * @param  list<string>  $genreSlugs
     * @return EloquentCollection<int, Media>
     */
    public function for(User $user, array $typeSlugs = [], array $genreSlugs = []): EloquentCollection
    {
        /** @var Closure(): Builder<Media> $offer */
        $offer = fn (): Builder => Media::query()
            ->trending()
            ->visibleTo($user)
            ->whereNotNull('media.cover_url')
            ->whereNotExists(fn ($library) => $library->select(DB::raw(1))->from('user_library')->whereColumn('user_library.media_id', 'media.id')->where('user_library.user_id', $user->id));

        $ids = [];

        if ($typeSlugs !== [] || $genreSlugs !== []) {
            $ids = $offer()
                ->when($typeSlugs !== [], fn ($stories) => $stories->whereIn('media.media_type_id', MediaType::query()->whereIn('slug', $typeSlugs)->select('id')))
                ->when($genreSlugs !== [], fn ($stories) => $stories->inGenres($genreSlugs))
                ->limit(self::LIMIT)
                ->pluck('media.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
        }

        if (count($ids) < self::LIMIT) {
            $ids = [...$ids, ...$offer()
                ->whereNotIn('media.id', $ids)
                ->limit(self::LIMIT - count($ids))
                ->pluck('media.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all()];
        }

        $stories = Media::query()->whereIn('id', $ids)->with('mediaType')->get()->keyBy('id');

        return new EloquentCollection(array_values(array_filter(array_map(fn (int $id): ?Media => $stories->get($id), $ids))));
    }
}
