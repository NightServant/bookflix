<?php

namespace App\Discovery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One titled row of the home screen. `items` are media for the story shelves and main Dewey classes (each with a `stories_count`)
 * for the subject shelf. Only the featured shelf (Trending Now) gets the gradient. `reasonLabel` is the eyebrow over the title; `description`
 * and `href` (where "See all" goes) are for a shelf that is a page of its own, like an editorial collection.
 */
final readonly class Shelf
{
    /**
     * @param  Collection<int, covariant Model>  $items
     */
    public function __construct(
        public string $key,
        public string $title,
        public Collection $items,
        public bool $featured = false,
        public ?string $reasonLabel = null,
        public ?string $description = null,
        public ?string $href = null,
    ) {}
}
