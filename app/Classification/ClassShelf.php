<?php

namespace App\Classification;

use App\Models\DeweyClass;
use App\Models\Media;
use Illuminate\Support\Collection;

readonly class ClassShelf
{
    /**
     * @param  Collection<int, Media>  $media
     */
    public function __construct(
        public DeweyClass $class,
        public Collection $media,
    ) {}
}
