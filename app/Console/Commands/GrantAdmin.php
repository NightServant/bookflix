<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookflix:grant-admin {email : The email address of the user to promote}')]
#[Description('Grant admin access to the user with the given email address')]
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

        $user->is_admin = true;
        $user->save();

        $this->info("{$user->email} is now an admin.");

        return self::SUCCESS;
    }
}
