<?php

namespace App\Console\Commands;

use App\Catalog\Popularity\PopularityScorer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookflix:score-popularity')]
#[Description('Blend the providers\' popularity metrics into each media\'s trending score')]
class ScorePopularity extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PopularityScorer $scorer): int
    {
        $this->info("Scored {$scorer->run()} media.");

        return self::SUCCESS;
    }
}
