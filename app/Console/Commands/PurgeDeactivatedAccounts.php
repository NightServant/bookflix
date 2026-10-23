<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('bookflix:purge-deactivated-accounts')]
#[Description('Permanently delete accounts deactivated longer than the retention period')]
class PurgeDeactivatedAccounts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cutoff = now()->subDays(config('bookflix.privacy.deactivated_retention_days'));
        $purged = 0;

        User::query()
            ->where('deactivated_at', '<', $cutoff)
            ->lazyById()
            ->each(function (User $user) use (&$purged) {
                if ($user->profile->avatar_path) {
                    Storage::delete($user->profile->avatar_path);
                }

                $user->delete();
                $purged++;
            });

        $this->info("Purged {$purged} deactivated account(s).");

        return self::SUCCESS;
    }
}
