<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Admin')] class extends Component {
}; ?>

<section class="py-12">
    <h1 class="text-section text-ink">{{ __('Admin') }}</h1>

    <ul class="mt-4 space-y-2 text-body text-link">
        <li><a href="{{ route('admin.providers') }}" wire:navigate>{{ __('Providers and sync runs') }}</a></li>
        <li><a href="{{ route('admin.media.index') }}" wire:navigate>{{ __('Media') }}</a></li>
        <li><a href="{{ route('admin.duplicates') }}" wire:navigate>{{ __('Duplicate review') }}</a></li>
        <li><a href="{{ route('admin.classifications') }}" wire:navigate>{{ __('Classification review') }}</a></li>
        <li><a href="{{ route('admin.users') }}" wire:navigate>{{ __('Users') }}</a></li>
    </ul>
</section>
