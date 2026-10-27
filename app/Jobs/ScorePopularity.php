<?php

namespace App\Jobs;

use App\Catalog\Popularity\PopularityScorer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScorePopularity implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Two score runs would fight over the same rows; one queued run already covers a second request.
     */
    public int $uniqueFor = 1800;

    public function __construct()
    {
        $this->onQueue('ingestion');
    }

    public function handle(PopularityScorer $scorer): void
    {
        $scorer->run();
    }
}
