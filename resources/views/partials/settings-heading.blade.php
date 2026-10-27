{{-- The h1 of a settings page. Pass `title` (the section) and `description` (what the page is for and what to do next). --}}
<div class="relative mb-6 w-full">
    <x-page-header :eyebrow="__('Settings')" :title="$title" :description="$description" class="mb-6" />
    <flux:separator variant="subtle" />
</div>
