<?php

namespace App\Reader\Cbz;

/**
 * The pages of a comic in reading order. Page numbers are 1-based, the way a reader counts them.
 */
final readonly class CbzPageIndex
{
    /**
     * @param  list<CbzPage>  $pages
     */
    public function __construct(public array $pages) {}

    public function count(): int
    {
        return count($this->pages);
    }

    public function page(int $n): ?CbzPage
    {
        return $this->pages[$n - 1] ?? null;
    }
}
