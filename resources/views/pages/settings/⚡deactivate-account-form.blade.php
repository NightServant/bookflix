<?php

use Livewire\Component;

new class extends Component {}; ?>

<div class="space-y-3 pt-6">
    <h2 class="text-meta font-medium text-ink-subtle">{{ __('Danger Zone') }}</h2>

    <x-settings-card danger :title="__('Deactivate account')" :description="__('Deactivate your account and sign out everywhere. Its data is permanently deleted after :days days.', ['days' => config('bookflix.privacy.deactivated_retention_days')])">
        <flux:modal.trigger name="confirm-account-deactivation">
            <x-cta variant="danger" icon="trash" icon:variant="outline" data-test="deactivate-account-button">
                {{ __('Deactivate account') }}
            </x-cta>
        </flux:modal.trigger>

        <livewire:pages::settings.deactivate-account-modal />
    </x-settings-card>
</div>
