<?php

namespace App\Classification;

use App\Access\MatureAccess;
use App\Classification\Dewey\DeweyHierarchy;
use App\Models\DeweyClass;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DeweyCounts
{
    private const VERSION_KEY = 'dewey:count:version';

    /**
     * How many stories the reader can see with a public primary class in this class's subtree, cached for an hour (one count for readers who may see
     * mature and adult titles, one for everyone else, so a gated reader's number matches the stories they can reach; no reader is gated).
     */
    public function forClass(DeweyClass $class, ?User $viewer = null): int
    {
        $version = Cache::rememberForever(self::VERSION_KEY, fn () => (string) Str::uuid());
        $gate = MatureAccess::cacheKey($viewer);

        return Cache::remember("dewey:count:{$version}:{$gate}:{$class->id}", 3600, fn () => Media::inDeweySubtree($class)->visibleTo($viewer)->count());
    }

    /**
     * The main classes that hold at least one story, each with its `stories_count` set (the tiles on Home and Discover read it).
     *
     * @return Collection<int, DeweyClass>
     */
    public function rootsWithCounts(?User $viewer = null): Collection
    {
        return app(DeweyHierarchy::class)->roots()
            ->each(fn (DeweyClass $class) => $class->setAttribute('stories_count', $this->forClass($class, $viewer)))
            ->filter(fn (DeweyClass $class): bool => $class->stories_count > 0)
            ->values();
    }

    /**
     * Forget every cached count by moving to a new key version (works on any cache store); call it after any classification change.
     */
    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, (string) Str::uuid());
    }
}
