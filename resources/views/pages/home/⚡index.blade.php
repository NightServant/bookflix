<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Home')] class extends Component {
}; ?>

<section class="py-12">
    <h1 class="text-section text-ink">{{ __('Your library is just getting started') }}</h1>
    <p class="mt-2 text-body text-ink-subtle">{{ __('Set up your reading profile to personalize your shelves.') }}</p>

    <flux:button variant="primary" class="mt-6" :href="route('profile.edit')" wire:navigate>
        {{ __('Set up your reading profile') }}
    </flux:button>
</section>
