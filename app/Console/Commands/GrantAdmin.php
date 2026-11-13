<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookflix:grant-admin {email : The email address of the user to promote} {--revoke : Take admin access away instead, making the account a reader again}')]
#[Description('Grant admin access to the user with the given email address, or take it away with --revoke')]
class GrantAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user found with that email address.');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $user->is_admin = false;
            $user->save();

            $this->info("{$user->email} is now a reader. They reach the reader side (Home, Library and the rest) and no admin page.");

            if (! User::query()->where('is_admin', true)->exists()) {
                $this->warn('No admin account is left. Create one with bookflix:make-admin.');
            }

            return self::SUCCESS;
        }

        $user->is_admin = true;
        $user->save();

        $this->info("{$user->email} is now an admin. They reach the admin area only: the reader side (Home, Library and the rest) is closed to an admin account.");

        return self::SUCCESS;
    }
}
