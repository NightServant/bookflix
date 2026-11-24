<?php

namespace App\Console\Commands;

use App\Ai\KillSwitch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookflix:ai-resume')]
#[Description('Lift the AI kill switch')]
class AiResume extends Command
{
    public function handle(KillSwitch $killSwitch): int
    {
        $killSwitch->resume();
        $this->info('AI is back on (when enabled and configured).');

        return self::SUCCESS;
    }
}
