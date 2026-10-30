@props(['families', 'open' => []])

{{--
    The genre tree as a typographic list: one row for each family, with its own checkbox (picking a family means everything below it) and the
    number of stories it holds, and its subgenres behind a button that opens them as chips grouped under the family's display groups. Never a
    grid of cards. `families` is the shape of TaxonomyFacets::genres(), so what a reader may be offered (the mature family) is the caller's
    decision. A subgenre listed under two families is the same checkbox twice, ticked in both. `open` lists the family slugs that start open
    (the ones that already hold a choice). Bind with `wire:model` to an array of slugs.
--}}
<ul {{ $attributes->whereDoesntStartWith(['wire:model', 'x-model'])->class('divide-y divide-hairline border-y border-hairline') }}>
    @foreach ($families as $family)
        @php
            $subgenres = collect($family['groups'])->sum(fn (array $group): int => count($group['items']));
            $panel = 'genre-'.$family['slug'];
            $startsOpen = in_array($family['slug'], $open, true);
        @endphp

        <li wire:key="family-{{ $family['slug'] }}" x-data="{ open: {{ $startsOpen ? 'true' : 'false' }} }">
            <div class="flex items-center justify-between gap-4 py-1">
                <label class="group flex min-h-11 min-w-0 flex-1 cursor-pointer items-center gap-3">
                    <input type="checkbox" value="{{ $family['slug'] }}" class="peer sr-only" {{ $attributes->whereStartsWith(['wire:model', 'x-model']) }}>
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-control border border-border-input text-canvas peer-checked:border-ink peer-checked:bg-ink peer-focus-visible:ring-2 peer-focus-visible:ring-accent-electric peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-canvas">
                        <flux:icon name="check" variant="micro" class="hidden size-4 group-has-[:checked]:block" />
                    </span>
                    <span class="min-w-0 text-body text-ink text-pretty">{{ $family['name'] }}</span>
                    <span class="shrink-0 text-meta tabular-nums text-ink-subtle">{{ number_format($family['count']) }}</span>
                </label>

                @if ($subgenres > 0)
                    <button
                        type="button"
                        aria-expanded="{{ $startsOpen ? 'true' : 'false' }}"
                        aria-controls="{{ $panel }}"
                        :aria-expanded="open"
                        @click="open = ! open"
                        class="focus-ring inline-flex min-h-11 shrink-0 items-center gap-2 rounded-control px-3 text-meta text-ink-subtle hover:text-ink"
                    >
                        {{ trans_choice(':count subgenre|:count subgenres', $subgenres) }}
                        <flux:icon name="chevron-down" variant="micro" class="size-4" x-bind:class="open ? 'rotate-180' : ''" />
                    </button>
                @endif
            </div>

            @if ($subgenres > 0)
                <div id="{{ $panel }}" x-show="open" @unless ($startsOpen) style="display: none" @endunless class="space-y-5 pt-2 pb-6">
                    @foreach ($family['groups'] as $group)
                        <x-choice-chips
                            wire:key="group-{{ $family['slug'] }}-{{ $loop->index }}"
                            :legend="$group['name'] ?? __('Subgenres of :family', ['family' => $family['name']])"
                            :hide-legend="$group['name'] === null"
                            :options="collect($group['items'])->pluck('name', 'slug')->all()"
                            {{ $attributes->whereStartsWith(['wire:model', 'x-model']) }}
                        />
                    @endforeach
                </div>
            @endif
        </li>
    @endforeach
</ul>
