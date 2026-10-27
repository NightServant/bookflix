{{--
    The filter controls of the search page, rendered twice: inline from the lg breakpoint, and inside the mobile <x-filter-sheet>.
    Every control binds `filters.*` on the page; `$facets` is the page's `facets` computed.
--}}
<div class="flex flex-col gap-5 [&>*+*]:border-t [&>*+*]:border-hairline [&>*+*]:pt-5">
    <flux:checkbox.group wire:model.live="filters.mediaTypes" :label="__('Type')" class="grid grid-cols-2 gap-x-2 *:data-flux-field:mb-0! *:data-flux-field:min-h-11 *:data-flux-field:items-center lg:*:data-flux-field:min-h-10">
        @foreach ($facets['types'] as $slug => $name)
            <flux:checkbox :value="$slug" :label="$name" wire:key="type-{{ $slug }}" />
        @endforeach
    </flux:checkbox.group>

    <flux:checkbox.group wire:model.live="filters.statuses" :label="__('Status')" class="grid grid-cols-2 gap-x-2 *:data-flux-field:mb-0! *:data-flux-field:min-h-11 *:data-flux-field:items-center lg:*:data-flux-field:min-h-10">
        @foreach ($facets['statuses'] as $value => $label)
            <flux:checkbox :value="$value" :label="$label" wire:key="status-{{ $value }}" />
        @endforeach
    </flux:checkbox.group>

    <x-combobox multiple data-test="filter-genres" wire:model.live="filters.genres" :label="__('Genres')" :options="$facets['genres']" :placeholder="__('Search genres')" />

    <x-combobox multiple data-test="filter-themes" wire:model.live="filters.themes" :label="__('Themes')" :options="$facets['themes']" :placeholder="__('Search themes')" />

    <x-combobox multiple data-test="filter-languages" wire:model.live="filters.languages" :label="__('Languages')" :options="$facets['languages']" :placeholder="__('Search languages')" />

    <x-range-filter wire:model.live="filters.years" :label="__('Year')" :min="$facets['years'][0]" :max="$facets['years'][1]" />

    <flux:select wire:model.live="filters.length" :label="__('Length')">
        <flux:select.option value="">{{ __('Any length') }}</flux:select.option>
        @foreach ($facets['lengths'] as $value => $label)
            <flux:select.option :value="$value">{{ $label }}</flux:select.option>
        @endforeach
    </flux:select>

    <flux:select wire:model.live="filters.minRating" :label="__('Rating')">
        <flux:select.option value="">{{ __('Any rating') }}</flux:select.option>
        @foreach ($facets['ratings'] as $value => $label)
            <flux:select.option :value="$value">{{ $label }}</flux:select.option>
        @endforeach
    </flux:select>
</div>
