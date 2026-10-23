<?php

namespace App\Console\Commands;

use App\Enums\ClassificationType;
use App\Models\Media;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('bookflix:classification-coverage')]
#[Description('Show how many media have a primary class the public can see')]
class ClassificationCoverage extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $total = Media::count();
        $covered = Media::whereHas('deweyClasses', fn (Builder $rows) => $rows->public()->where('classification_type', ClassificationType::Primary))->count();

        $this->info(sprintf('Coverage: %.1f%% (%d/%d)', $total === 0 ? 0 : $covered / $total * 100, $covered, $total));

        return self::SUCCESS;
    }
}
