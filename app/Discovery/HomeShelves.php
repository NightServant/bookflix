<?php

namespace App\Discovery;

use App\Classification\DeweyCounts;
use App\Models\Media;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * The shelves of the home screen that do not depend on who is looking (Phase 6 adds the personal ones around them).
 */
class HomeShelves
{
    /**
     * The shelves in the order they appear, with their titles and which one is featured; the skeletons read this too.
     *
     * @var array<string, array{title: string, featured: bool}>
     */
    public const SHELVES = [
        'trending' => ['title' => 'Trending Now', 'featured' => true],
        'new-releases' => ['title' => 'New Releases', 'featured' => false],
        'explore-by-subject' => ['title' => 'Explore by Subject', 'featured' => false],
    ];

    private const CACHE_MINUTES = 10;

    /**
     * Trending Now, New Releases and Explore by Subject, in that order, every one present even when empty.
     *
     * @return Collection<int, Shelf>
     */
    public function guestSafe(int $perShelf = 20): Collection
    {
        return collect(array_keys(self::SHELVES))->map(fn (string $key): Shelf => $this->shelf($key, $perShelf))->values();
    }

    /**
     * One shelf by key (a `SHELVES` key). The story picks are kept ten minutes as ids (the cache refuses to unserialize models), then
     * loaded fresh, so a story merged or deleted in the meantime drops out; each story comes with what a tile, preview or quick view
     * reads. The subject shelf holds the main Dewey classes that have stories, with their `stories_count`.
     */
    public function shelf(string $key, int $perShelf = 20): Shelf
    {
        $definition = self::SHELVES[$key] ?? throw new InvalidArgumentException("Unknown home shelf [{$key}].");

        return new Shelf($key, __($definition['title']), $key === 'explore-by-subject' ? app(DeweyCounts::class)->rootsWithCounts()->toBase() : $this->stories($key, $perShelf), $definition['featured']);
    }

    /**
     * The stories for the hero slider: the most trending that have a cover to fill the backdrop, with what the slides read.
     *
     * @return EloquentCollection<int, Media>
     */
    public function hero(int $count = 5): EloquentCollection
    {
        return Media::trending()->whereNotNull('media.cover_url')->with(['mediaType', 'genres'])->limit($count)->get();
    }

    /**
     * @return Collection<int, Media>
     */
    private function stories(string $key, int $perShelf): Collection
    {
        $picks = Cache::remember("home:shelves:v1:{$perShelf}", now()->addMinutes(self::CACHE_MINUTES), fn (): array => $this->pick($perShelf));

        $media = Media::query()->whereIn('id', $picks[$key])->with(['mediaType', 'publicPrimaryDewey.deweyClass'])->get()->keyBy('id');

        return collect($picks[$key])->map(fn (int $id) => $media->get($id))->filter()->values();
    }

    /**
     * @return array{trending: list<int>, new-releases: list<int>}
     */
    private function pick(int $perShelf): array
    {
        $trending = array_values(Media::trending()->limit($perShelf)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

        // Until ratings exist the two story shelves would rank alike, so the stories already trending go to the back of the line.
        $releases = Media::query()->unmerged()
            ->whereNotNull('media.published_on')
            ->where('media.published_on', '<=', now()->addMonth())
            ->orderByRaw('media.published_on desc')
            ->orderByDesc('media.id')
            ->limit($perShelf * 2)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sortBy(fn (int $id): bool => in_array($id, $trending, true))
            ->take($perShelf)
            ->values();

        return ['trending' => $trending, 'new-releases' => array_values($releases->all())];
    }
}
