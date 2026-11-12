{{--
    What the search understood from the reader's words, in two rows above the filters. "From your search": applied values, outlined, each removes itself
    from this search. "Narrow by": values the search only offers (they would have left no results, or the words named a title), dashed with a +;
    tapping one adds it to the reader's own filters. Both are real buttons named for what they do. See dismissIntent() and addIntentFilter().
--}}
@php
    $intentRows = [
        ['key' => 'applied', 'label' => __('From your search'), 'chips' => $this->intentChips['applied']],
        ['key' => 'suggested', 'label' => __('Narrow by'), 'chips' => $this->intentChips['suggested']],
    ];
@endphp

@if ($intentRows[0]['chips'] !== [] || $intentRows[1]['chips'] !== [])
    <div class="mt-6 flex flex-col gap-x-3 gap-y-2">
        @foreach ($intentRows as $row)
            @if ($row['chips'] !== [])
                <div wire:key="intent-row-{{ $row['key'] }}" class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <span class="text-legal tracking-wide text-ink-subtle uppercase">{{ $row['label'] }}</span>

                    <ul aria-label="{{ $row['label'] }}" class="flex flex-wrap items-center gap-2">
                        @foreach ($row['chips'] as $chip)
                            <li wire:key="intent-{{ $row['key'] }}-{{ $chip['group'] }}-{{ $chip['value'] }}">
                                @if ($row['key'] === 'applied')
                                    <x-chip intent="applied" wire:click="dismissIntent('{{ $chip['group'] }}', @js($chip['value']))" aria-label="{{ __('Remove :label from your search', ['label' => $chip['text']]) }}">{{ $chip['text'] }}</x-chip>
                                @else
                                    <x-chip intent="suggested" wire:click="addIntentFilter('{{ $chip['group'] }}', @js($chip['value']))" aria-label="{{ __('Filter by :label', ['label' => $chip['text']]) }}">{{ $chip['text'] }}</x-chip>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endforeach
    </div>
@endif
