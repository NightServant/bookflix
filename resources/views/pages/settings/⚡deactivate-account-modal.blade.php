<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Deactivate the currently authenticated user and sign them out everywhere.
     */
    public function deactivateAccount(Logout $logout): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        $user = Auth::user();

        $user->forceFill(['deactivated_at' => now()])->save();

        DB::table('sessions')->where('user_id', $user->id)->delete();

        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<flux:modal name="confirm-account-deactivation" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form method="POST" wire:submit="deactivateAccount" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Are you sure you want to deactivate your account?') }}</flux:heading>

            <flux:subheading>
                {{ __('You will be signed out everywhere and will not be able to log in again. Your account and all of its data will be permanently deleted after :days days. Please enter your password to confirm.', ['days' => config('bookflix.privacy.deactivated_retention_days')]) }}
            </flux:subheading>
        </div>

        <flux:input wire:model="password" :label="__('Password')" type="password" viewable />

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="danger" type="submit" data-test="confirm-deactivate-account-button">
                {{ __('Deactivate account') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
