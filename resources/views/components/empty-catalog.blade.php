@props(['heading' => 'h2'])

{{--
    The empty state of every catalog screen: what is going on, then the next step. A reader is told the catalog fills as providers
    sync and can use the wait to set up their reading profile; an admin gets the one primary action, "Sync providers".
    `heading` is the tag of the title (`h1` when the state is the whole page).
--}}
<div {{ $attributes->class('max-w-prose') }}>
    <{{ $heading }} class="text-section tracking-tight text-ink">{{ __('The catalog is empty') }}</{{ $heading }}>

    @can('access-admin')
        <p class="mt-2 text-body text-ink-subtle">{{ __('Stories appear here once a metadata provider has synced. Run a sync from the providers page to fill it.') }}</p>

        <div class="mt-6 flex flex-wrap gap-3">
            <x-cta :href="route('admin.providers')" wire:navigate>{{ __('Sync providers') }}</x-cta>
            <x-cta variant="secondary" :href="route('profile.edit')" wire:navigate>{{ __('Set up your reading profile') }}</x-cta>
        </div>
    @else
        <p class="mt-2 text-body text-ink-subtle">{{ __('Stories appear here once the catalog has been synced, which an administrator does. Until then there is nothing to browse. You can set up your reading profile now, so your shelves start personal.') }}</p>

        <div class="mt-6">
            <x-cta variant="secondary" :href="route('profile.edit')" wire:navigate>{{ __('Set up your reading profile') }}</x-cta>
        </div>
    @endcan
</div>
