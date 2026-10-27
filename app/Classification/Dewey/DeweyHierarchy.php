<?php

namespace App\Classification\Dewey;

use App\Models\DeweyClass;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Cache;

class DeweyHierarchy
{
    /**
     * The main classes, cached for an hour as plain rows, because the cache refuses to unserialize objects
     * (`cache.serializable_classes`). The seeder clears the cache.
     *
     * @return Collection<int, DeweyClass>
     */
    public function roots(): Collection
    {
        return DeweyClass::hydrate(Cache::remember('dewey:roots', 3600, fn (): array => DeweyClass::whereNull('parent_id')->orderBy('code')->get()->map->getAttributes()->all()));
    }

    /**
     * @return Collection<int, DeweyClass>
     */
    public function children(DeweyClass $class): Collection
    {
        return DeweyClass::where('parent_id', $class->id)->orderBy('code')->get();
    }

    /**
     * The classes above this one, root first, not including itself.
     *
     * @return Collection<int, DeweyClass>
     */
    public function ancestors(DeweyClass $class): Collection
    {
        $codes = array_slice(explode('/', $class->path), 0, -1);

        return DeweyClass::whereIn('code', $codes)->orderBy('level')->get();
    }

    public function findByCode(string $code): ?DeweyClass
    {
        return DeweyClass::where('code', $code)->first();
    }

    /**
     * The ids of the class and everything below it.
     *
     * @return BaseCollection<int, int>
     */
    public function subtreeIds(DeweyClass $class): BaseCollection
    {
        return DeweyClass::subtree($class)->pluck('id');
    }

    /**
     * Depth of the lowest shared ancestor over the deeper class's depth: 1.0 for the same class, 0.0 when the roots differ.
     */
    public function similarity(DeweyClass $a, DeweyClass $b): float
    {
        $shared = 0;

        foreach (array_map(null, explode('/', $a->path), explode('/', $b->path)) as [$left, $right]) {
            if ($left === null || $left !== $right) {
                break;
            }

            $shared++;
        }

        return $shared / max($a->level, $b->level);
    }
}
