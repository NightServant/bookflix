<?php

use Livewire\Component;

new class extends Component {}; ?>

<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <flux:heading>{{ __('Deactivate account') }}</flux:heading>
        <flux:subheading>{{ __('Deactivate your account and sign out everywhere. Its data is permanently deleted after :days days.', ['days' => config('bookflix.privacy.deactivated_retention_days')]) }}</flux:subheading>
    </div>

    <flux:modal.trigger name="confirm-account-deactivation">
        <flux:button variant="danger" data-test="deactivate-account-button">
            {{ __('Deactivate account') }}
        </flux:button>
    </flux:modal.trigger>

    <livewire:pages::settings.deactivate-account-modal />
</section>
