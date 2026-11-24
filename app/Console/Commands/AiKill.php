<?php

namespace App\Console\Commands;

use App\Ai\KillSwitch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookflix:ai-kill')]
#[Description('Stop every AI call at once; search and the rest fall back to the non-AI path')]
class AiKill extends Command
{
    public function handle(KillSwitch $killSwitch): int
    {
        $killSwitch->kill();
        $this->info('AI is stopped. Run bookflix:ai-resume to turn it back on.');

        return self::SUCCESS;
    }
}
