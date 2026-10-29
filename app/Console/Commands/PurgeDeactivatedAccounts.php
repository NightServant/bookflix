<?php

namespace App\Console\Commands;

use App\Actions\Ratings\RecalculateRatedMedia;
use App\Library\ExportStorage;
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
    public function handle(RecalculateRatedMedia $ratings, ExportStorage $exports): int
    {
        $cutoff = now()->subDays(config('bookflix.privacy.deactivated_retention_days'));
        $purged = 0;

        User::query()
            ->where('deactivated_at', '<', $cutoff)
            ->lazyById()
            ->each(function (User $user) use ($ratings, $exports, &$purged) {
                if ($user->profile->avatar_path) {
                    Storage::disk(config('bookflix.avatar_disk'))->delete($user->profile->avatar_path);
                }

                // The account's scores go with it. Its stories are refreshed first: a reader deactivated before that was wired never refreshed them.
                $ratings->handle($user);
                $exports->clear($user->id);
                $user->delete();
                $purged++;
            });

        $this->info("Purged {$purged} deactivated account(s).");

        return self::SUCCESS;
    }
}
