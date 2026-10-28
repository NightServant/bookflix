@props(['name', 'title'])

{{--
    One disclosure section of <x-filter-panel>: a heading that is a real button (aria-expanded, aria-controls), the number of choices made inside it,
    and a chevron. The content is built when the section opens (`x-if`), so a closed section costs nothing; the wrapper stays so aria-controls always
    points at something. State and counts live in the panel's Alpine data (resources/js/filter-panel.js).
--}}
<section class="border-t border-hairline first:border-t-0">
    <h3>
        <button
            type="button"
            @click="toggleSection('{{ $name }}')"
            :aria-expanded="isOpen('{{ $name }}')"
            aria-controls="fp-{{ $name }}"
            class="focus-ring -mx-2 flex min-h-12 w-[calc(100%+1rem)] items-center justify-between gap-3 rounded-control px-2 text-start text-body font-medium text-ink hover:bg-surface-input"
        >
            <span>{{ $title }}</span>
            <span class="flex items-center gap-2 text-ink-subtle">
                <span x-show="sectionCount('{{ $name }}') > 0" style="display: none" class="text-meta font-normal tabular-nums">
                    <span x-text="sectionCount('{{ $name }}')"></span><span class="sr-only"> {{ __('chosen') }}</span>
                </span>
                <flux:icon name="chevron-down" variant="micro" class="size-5 motion-safe:transition-transform motion-safe:duration-(--motion-fast)" x-bind:class="isOpen('{{ $name }}') && 'rotate-180'" />
            </span>
        </button>
    </h3>

    <div id="fp-{{ $name }}" x-show="isOpen('{{ $name }}')" style="display: none" class="pb-4">
        <template x-if="isOpen('{{ $name }}')">
            <div>{{ $slot }}</div>
        </template>
    </div>
</section>
