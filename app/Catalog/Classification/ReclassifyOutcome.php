<?php

namespace App\Catalog\Classification;

/**
 * What re-deriving one media changed (or, in a dry run, would change).
 */
final readonly class ReclassifyOutcome
{
    /**
     * @param  array{genres: int, themes: int, tropes: int, demographics: int, warnings: int}  $attached  Links added.
     */
    public function __construct(
        public int $mediaId,
        public string $oldType,
        public string $newType,
        public float $confidence = 1.0,
        public array $attached = ['genres' => 0, 'themes' => 0, 'tropes' => 0, 'demographics' => 0, 'warnings' => 0],
        public int $tagsFolded = 0,
        public bool $ratingSet = false,
        public bool $prohibited = false,
    ) {}

    public function typeChanged(): bool
    {
        return $this->oldType !== $this->newType;
    }

    public function changedAnything(): bool
    {
        return $this->typeChanged() || $this->prohibited || $this->ratingSet || $this->tagsFolded > 0 || array_sum($this->attached) > 0;
    }
}
