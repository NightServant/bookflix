@props(['title', 'description', 'back' => null, 'backLabel' => null, 'eyebrow' => null])

{{-- The head of every admin page: the page header with the page's buttons on the right. The sidebar is the way between pages, so only a page that sits
     under another (a record's edit page) passes `back` for a quiet way up. --}}
<div>
    @if ($back)
        <flux:button variant="ghost" size="sm" icon="arrow-left" icon:variant="outline" class="-ms-2 min-h-11" :href="$back" wire:navigate>{{ $backLabel }}</flux:button>
    @endif

    <x-page-header :class="$back ? 'mt-2' : ''" :eyebrow="$eyebrow" :title="$title" :description="$description">
        @isset($actions)
            <x-slot:actions>{{ $actions }}</x-slot:actions>
        @endisset
    </x-page-header>
</div>
