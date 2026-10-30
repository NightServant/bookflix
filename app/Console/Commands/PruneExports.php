<?php

namespace App\Console\Commands;

use App\Library\ExportStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookflix:prune-exports')]
#[Description('Delete data export files older than the retention period')]
class PruneExports extends Command
{
    public function handle(ExportStorage $exports): int
    {
        $this->info("Pruned {$exports->prune()} data export(s).");

        return self::SUCCESS;
    }
}
