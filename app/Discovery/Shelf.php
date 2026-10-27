<?php

namespace App\Discovery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One titled row of the home screen. `items` are media for the story shelves and main Dewey classes (each with a `stories_count`)
 * for the subject shelf. Only the featured shelf (Trending Now) gets the gradient.
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
    ) {}
}
