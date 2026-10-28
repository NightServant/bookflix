{{--
    What a direct link to a mature or adult story answers while the reader has not turned those titles on (HTTP 403): nothing of the title, only
    why and where to change it. The same gate hides these stories from search, Discover, shelves and recommendations (App\Access\MatureAccess).
--}}
<x-layouts::app :title="__('Mature title')">
    <x-page-header
        :eyebrow="__('Mature and adult titles')"
        :title="__('This title is for adult readers')"
        :description="__('Mature and adult titles stay hidden until you turn them on in your privacy settings, where you confirm that you are 18 or older.')"
    />

    <div class="mt-8 flex flex-wrap gap-3">
        <x-cta icon="shield-check" :href="route('privacy.edit')" wire:navigate>{{ __('Open privacy settings') }}</x-cta>
        <x-cta variant="secondary" icon="compass" :href="route('discover')" wire:navigate>{{ __('Browse Discover') }}</x-cta>
    </div>
</x-layouts::app>
