<?php

namespace App\Classification;

use App\Models\DeweyClass;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use Illuminate\Support\Collection;

class ClassShelves
{
    /**
     * Group stories into one shelf per class at the given level ('root' = main class, 'division'), ordered by code.
     * A story sits on the shelf of its public primary class's ancestor at that level; stories without one are left out.
     * Two queries however many stories: the primary rows joined to their class path, then the shelf classes.
     *
     * @param  Collection<int, Media>  $media
     * @return Collection<int, ClassShelf>
     */
    public function forMedia(Collection $media, string $level = 'root'): Collection
    {
        $depth = $level === 'division' ? 2 : 1;

        $codeByMedia = MediaDeweyClass::query()
            ->publicPrimary()
            ->whereIn('media_id', $media->pluck('id'))
            ->join('dewey_classes', 'dewey_classes.id', '=', 'media_dewey_classes.dewey_class_id')
            ->selectRaw("media_dewey_classes.media_id, nullif(split_part(dewey_classes.path, '/', ?), '') as shelf_code", [$depth])
            ->pluck('shelf_code', 'media_id')
            ->filter();

        return DeweyClass::whereIn('code', $codeByMedia->unique())->orderBy('code')->get()
            ->map(fn (DeweyClass $class) => new ClassShelf($class, $media->filter(fn (Media $item) => $codeByMedia->get($item->id) === $class->code)->values()))
            ->values();
    }
}
