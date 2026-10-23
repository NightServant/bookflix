<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Admin')] class extends Component {
}; ?>

<section class="py-12">
    <h1 class="text-section text-ink">{{ __('Admin') }}</h1>

    <ul class="mt-4 space-y-2 text-body text-link"></ul>
</section>
