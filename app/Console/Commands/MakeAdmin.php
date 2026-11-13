<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('bookflix:make-admin {email : The email address of the new admin} {--name=Bookflix Admin : The name shown for the account}')]
#[Description('Create a new admin account with a generated password, printed once')]
class MakeAdmin extends Command
{
    /**
     * Execute the console command.
     *
     * The password is generated here and never taken as an argument, so it cannot land in shell history; it is printed once and logged nowhere.
     */
    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:255']])->fails()) {
            $this->error('That is not a valid email address.');

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error('A user with that email address already exists. Use bookflix:grant-admin to promote an existing account.');

            return self::FAILURE;
        }

        $password = Str::password(24);

        DB::transaction(function () use ($email, $password): void {
            $user = new User(['name' => (string) $this->option('name'), 'email' => $email, 'password' => $password]);
            $user->is_admin = true;
            $user->email_verified_at = now();
            $user->save();

            // An admin never meets onboarding, which is a reader's first-run page (see EnsureOnboarded).
            $user->profile()->updateOrCreate([], ['onboarded_at' => now()]);
        });

        $this->info("Admin created for {$email}.");
        $this->line("Password: {$password}");
        $this->warn('Store this password now: it is shown only once. Change it after the first sign-in.');

        return self::SUCCESS;
    }
}
