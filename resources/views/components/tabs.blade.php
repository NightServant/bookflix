@props([
    'tabs',
    'selected' => null,
    'label' => null,
    'id' => null,
])

{{--
    Tabs after the APG tabs pattern, on @alpinejs/ui (automatic activation: arrows move focus and select).
    `tabs` maps a key to its label; each key has a named slot with that tab's panel:
    `<x-tabs :tabs="['about' => 'About']"><x-slot:about>…</x-slot:about></x-tabs>`. `selected` is the key to start on.
    The ui plugin supplies the keyboard handling and roving tabindex; the roles and ids are set here so they exist before Alpine boots.
--}}
@php
    $keys = array_keys($tabs);
    $default = max(0, (int) array_search($selected, $keys, true));
    $base = $id ?? 'tabs-'.dechex(crc32(json_encode($tabs)));
    $slots = get_defined_vars();
@endphp

<div x-data x-tabs default-index="{{ $default }}" {{ $attributes }}>
    <div x-tabs:list role="tablist" @if ($label) aria-label="{{ $label }}" @endif class="flex gap-1 overflow-x-auto border-b border-hairline [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        @foreach ($tabs as $key => $title)
            <button
                x-tabs:tab
                type="button"
                role="tab"
                id="{{ $base }}-tab-{{ $key }}"
                aria-controls="{{ $base }}-panel-{{ $key }}"
                aria-selected="{{ $loop->index === $default ? 'true' : 'false' }}"
                :aria-selected="$tab.isSelected"
                wire:key="{{ $base }}-tab-{{ $key }}"
                class="focus-ring -mb-px min-h-11 shrink-0 rounded-t-control border-b-2 border-transparent px-3 text-meta font-medium text-ink-subtle hover:text-ink aria-selected:border-ink aria-selected:text-ink"
            >{{ $title }}</button>
        @endforeach
    </div>

    <div x-tabs:panels class="pt-4">
        @foreach ($tabs as $key => $title)
            @php $panel = $slots[Str::camel($key)] ?? null; @endphp
            <div
                x-tabs:panel
                role="tabpanel"
                id="{{ $base }}-panel-{{ $key }}"
                aria-labelledby="{{ $base }}-tab-{{ $key }}"
                wire:key="{{ $base }}-panel-{{ $key }}"
                @if ($loop->index !== $default) style="display: none" @endif
                class="focus-ring rounded-card text-body text-ink"
            >{{ $panel instanceof \Illuminate\View\ComponentSlot ? $panel : '' }}</div>
        @endforeach
    </div>
</div>
