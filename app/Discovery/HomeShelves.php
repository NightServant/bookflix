<?php

namespace App\Discovery;

use App\Access\MatureAccess;
use App\Classification\DeweyCounts;
use App\Models\Media;
use App\Models\User;
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
     * @var array<string, array{title: string, featured: bool, description: string}>
     */
    public const SHELVES = [
        'trending' => ['title' => 'Trending Now', 'featured' => true, 'description' => 'The most popular stories in the catalog right now.'],
        'new-releases' => ['title' => 'New Releases', 'featured' => false, 'description' => 'The latest stories added to the catalog.'],
        'explore-by-subject' => ['title' => 'Explore by Subject', 'featured' => false, 'description' => 'Dewey classes, from the 000s to the 900s.'],
    ];

    private const CACHE_MINUTES = 10;

    /** The most slides the hero holds; the pick keeps this many ids and `hero()` takes as many as it is asked for. */
    private const HERO_SLIDES = 5;

    /**
     * Trending Now, New Releases and Explore by Subject, in that order, every one present even when empty.
     *
     * @return Collection<int, Shelf>
     */
    public function guestSafe(int $perShelf = 20, ?User $viewer = null): Collection
    {
        return collect(array_keys(self::SHELVES))->map(fn (string $key): Shelf => $this->shelf($key, $perShelf, $viewer))->values();
    }

    /**
     * One shelf by key (a `SHELVES` key). The story picks are kept ten minutes as ids (the cache refuses to unserialize models), then
     * loaded fresh, so a story merged or deleted in the meantime drops out; each story comes with what a tile, preview or quick view
     * reads. The subject shelf holds the main Dewey classes that have stories, with their `stories_count`.
     * Mature and adult titles are on a shelf only for a reader who opted in (see MatureAccess); the picks are kept per access level.
     */
    public function shelf(string $key, int $perShelf = 20, ?User $viewer = null): Shelf
    {
        $definition = self::SHELVES[$key] ?? throw new InvalidArgumentException("Unknown home shelf [{$key}].");

        return new Shelf($key, __($definition['title']), $key === 'explore-by-subject' ? app(DeweyCounts::class)->rootsWithCounts($viewer)->toBase() : $this->stories($key, $perShelf, $viewer), $definition['featured']);
    }

    /**
     * The stories for the hero slider: the most trending that have a cover to fill the backdrop, with what the slides read.
     *
     * @return EloquentCollection<int, Media>
     */
    public function hero(int $count = self::HERO_SLIDES, ?User $viewer = null): EloquentCollection
    {
        $ids = array_slice($this->picks(20, $viewer)['hero'], 0, $count);
        $media = Media::query()->whereIn('id', $ids)->with(['mediaType', 'genres'])->get()->keyBy('id');

        return new EloquentCollection(array_values(array_filter(array_map(fn (int $id): ?Media => $media->get($id), $ids))));
    }

    /**
     * @return Collection<int, Media>
     */
    private function stories(string $key, int $perShelf, ?User $viewer): Collection
    {
        $picks = $this->picks($perShelf, $viewer);

        $media = Media::query()->whereIn('id', $picks[$key])->with(['mediaType', 'publicPrimaryDewey.deweyClass'])->get()->keyBy('id');

        return collect($picks[$key])->map(fn (int $id) => $media->get($id))->filter()->values();
    }

    /**
     * The ids of every pick (the story shelves and the hero), kept ten minutes.
     *
     * @return array{trending: list<int>, new-releases: list<int>, hero: list<int>}
     */
    private function picks(int $perShelf, ?User $viewer): array
    {
        return Cache::remember('home:shelves:v2:'.MatureAccess::cacheKey($viewer).":{$perShelf}", now()->addMinutes(self::CACHE_MINUTES), fn (): array => $this->pick($perShelf, $viewer));
    }

    /**
     * @return array{trending: list<int>, new-releases: list<int>, hero: list<int>}
     */
    private function pick(int $perShelf, ?User $viewer): array
    {
        $trending = array_values(Media::trending()->visibleTo($viewer)->limit($perShelf)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

        // Until ratings exist the two story shelves would rank alike, so the stories already trending go to the back of the line.
        $releases = Media::query()->unmerged()->visibleTo($viewer)
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

        $hero = array_values(Media::trending()->visibleTo($viewer)->whereNotNull('media.cover_url')->limit(self::HERO_SLIDES)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

        return ['trending' => $trending, 'new-releases' => array_values($releases->all()), 'hero' => $hero];
    }
}
