<?php

namespace App\Classification;

use App\Access\MatureAccess;
use App\Classification\Dewey\DeweyHierarchy;
use App\Models\DeweyClass;
use App\Models\Media;
use App\Models\MediaDeweyClass;
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
        return Cache::remember($this->key($class, $viewer), 3600, fn () => Media::inDeweySubtree($class)->visibleTo($viewer)->count());
    }

    private function key(DeweyClass $class, ?User $viewer): string
    {
        $version = Cache::rememberForever(self::VERSION_KEY, fn () => (string) Str::uuid());

        return "dewey:count:{$version}:".MatureAccess::cacheKey($viewer).":{$class->id}";
    }

    /**
     * The counts of the classes that are not cached yet, all at once: the stories the reader can see grouped by their public primary class (one
     * row each, so nothing is counted twice), added up over each class's subtree, and kept as `forClass` keeps them. The same numbers as
     * `forClass`, in one query instead of one per class (the first Home of the hour waited for ten of them).
     *
     * @param  Collection<int, DeweyClass>  $classes
     */
    private function warm(Collection $classes, ?User $viewer): void
    {
        $cached = Cache::many($classes->mapWithKeys(fn (DeweyClass $class): array => [$this->key($class, $viewer) => $class->id])->keys()->all());
        $missing = $classes->filter(fn (DeweyClass $class): bool => ($cached[$this->key($class, $viewer)] ?? null) === null);

        if ($missing->isEmpty()) {
            return;
        }

        $perClass = MediaDeweyClass::query()->publicPrimary()
            ->whereIn('media_id', Media::query()->visibleTo($viewer)->select('media.id'))
            ->groupBy('dewey_class_id')->selectRaw('dewey_class_id, count(*) as total')->pluck('total', 'dewey_class_id');
        $paths = DeweyClass::query()->pluck('path', 'id');

        foreach ($missing as $class) {
            $total = $perClass->filter(fn ($count, $id): bool => $id === $class->id || str_starts_with((string) $paths[$id], "{$class->path}/"))->sum();

            Cache::put($this->key($class, $viewer), (int) $total, 3600);
        }
    }

    /**
     * The main classes that hold at least one story, each with its `stories_count` set (the tiles on Home and Discover read it).
     *
     * @return Collection<int, DeweyClass>
     */
    public function rootsWithCounts(?User $viewer = null): Collection
    {
        $roots = app(DeweyHierarchy::class)->roots();

        $this->warm($roots, $viewer);

        return $roots
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
