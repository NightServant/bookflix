<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Admin')] class extends Component {
}; ?>

<section class="py-12">
    <x-page-header :title="__('Admin')" :description="__('Run and review the catalog: sync providers, fix records, clear duplicates and manage users. Pick a task below.')" />

    <ul class="mt-6 space-y-2 text-body text-link">
        <li><a href="{{ route('admin.providers') }}" wire:navigate>{{ __('Providers and sync runs') }}</a></li>
        <li><a href="{{ route('admin.media.index') }}" wire:navigate>{{ __('Media') }}</a></li>
        <li><a href="{{ route('admin.duplicates') }}" wire:navigate>{{ __('Duplicate review') }}</a></li>
        <li><a href="{{ route('admin.classifications') }}" wire:navigate>{{ __('Classification review') }}</a></li>
        <li><a href="{{ route('admin.users') }}" wire:navigate>{{ __('Users') }}</a></li>
    </ul>
</section>
