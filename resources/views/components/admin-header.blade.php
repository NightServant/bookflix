@props(['title', 'description', 'back', 'backLabel' => null, 'eyebrow' => null])

{{-- The head of every admin page under the dashboard: a quiet way back, then the page header with the page's buttons on the right. --}}
<div>
    <flux:button variant="ghost" size="sm" icon="arrow-left" icon:variant="outline" class="-ms-2" :href="$back" wire:navigate>{{ $backLabel ?? __('Admin') }}</flux:button>

    <x-page-header class="mt-2" :eyebrow="$eyebrow" :title="$title" :description="$description">
        @isset($actions)
            <x-slot:actions>{{ $actions }}</x-slot:actions>
        @endisset
    </x-page-header>
</div>
