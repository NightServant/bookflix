{{--
    The active filters of a page that uses FilteredPage, grouped and labelled: "Genre  Isekai ×  Romance ×", "Theme  War ×", and a content warning says
    what it does: "Hide: Gore ×". Each chip has a remove button (a 44px target inside a 36px pill); the last item clears them all.
    Rendered by Livewire, so the chips always show what the page has applied; the panel follows the same state (resources/js/filter-panel.js).
--}}
@if ($this->chips !== [])
    <ul aria-label="{{ __('Active filters') }}" class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-3">
        @foreach ($this->chips as $group)
            <li wire:key="chip-group-{{ $group['group'] }}" class="flex flex-wrap items-center gap-x-3 gap-y-2">
                @if ($group['label'])
                    <span class="text-legal tracking-wide text-ink-subtle uppercase">{{ $group['label'] }}</span>
                @endif

                <ul aria-label="{{ $group['label'] ?? __('Hidden content warnings') }}" class="flex flex-wrap items-center gap-2">
                    @foreach ($group['chips'] as $chip)
                        <li
                            wire:key="chip-{{ $group['group'] }}-{{ $chip['value'] }}"
                            @class([
                                'inline-flex h-9 items-center rounded-full ps-3 text-meta text-ink',
                                'bg-surface-input' => $group['group'] !== 'excludeWarnings',
                                'ring-1 ring-inset ring-border-input' => $group['group'] === 'excludeWarnings',
                            ])
                        >
                            <span>{{ $chip['text'] }}</span>
                            <button
                                type="button"
                                wire:click="removeFilter('{{ $group['group'] }}', @js($chip['value']))"
                                aria-label="{{ $chip['remove'] }}"
                                class="focus-ring relative inline-flex size-9 items-center justify-center rounded-full text-ink-subtle after:absolute after:-inset-y-1 after:-inset-x-0.5 after:content-[''] hover:text-ink"
                            >
                                <flux:icon name="x-mark" class="size-4" />
                            </button>
                        </li>
                    @endforeach
                </ul>
            </li>
        @endforeach

        <li wire:key="chips-clear">
            <button type="button" wire:click="clearFilters" class="focus-ring inline-flex min-h-11 items-center gap-1 rounded-control px-3 text-meta text-link hover:underline"><flux:icon name="x-mark" variant="micro" class="size-4" />{{ __('Clear all') }}</button>
        </li>
    </ul>
@endif
