@props(['heading' => 'h2'])

{{--
    The empty state of every catalog screen: what is going on, then the next step, under a tilted book (<x-empty-state>). A reader is told the catalog fills as
    providers sync and can use the wait to set up their reading profile. Admins never see this: they have no reader pages.
    `heading` is the tag of the title (`h1` when the state is the whole page).
--}}
<x-empty-state :title="__('The catalog is empty')" :heading="$heading" {{ $attributes }}>
    <p class="mt-2 text-body text-ink-subtle">{{ __('Stories appear here once the catalog has been synced, which an administrator does. Until then there is nothing to browse. You can set up your reading profile now, so your shelves start personal.') }}</p>

    <div class="mt-6">
        <x-cta variant="secondary" icon="user" :href="route('profile.edit')" wire:navigate>{{ __('Set up your reading profile') }}</x-cta>
    </div>
</x-empty-state>
