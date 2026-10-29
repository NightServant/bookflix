<a {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 rounded-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-electric']) }}>
    <x-app-logo-icon class="size-8 shrink-0" />
    <span class="text-xl font-black tracking-tight text-ink">{{ config('app.name', 'Bookflix') }}</span>
</a>
