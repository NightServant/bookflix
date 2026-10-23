<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Home')] class extends Component {
}; ?>

<section class="py-12">
    <h1 class="text-section text-ink">{{ __('Your library is empty') }}</h1>
    <p class="mt-2 text-body text-ink-subtle">{{ __('Add your first book to start building your library.') }}</p>

    <flux:button variant="primary" class="mt-6" disabled>{{ __('Add a book') }}</flux:button>
</section>
