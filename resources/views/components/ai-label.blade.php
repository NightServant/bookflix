{{-- A small subdued pill that marks text a model wrote (P11-R15). Not red: it is a note, not a warning. --}}
<span {{ $attributes->class('inline-flex items-center gap-1 rounded-full bg-surface-input px-2 py-0.5 text-meta text-ink-subtle') }}>
    <flux:icon name="sparkles" variant="micro" class="size-3.5" />
    {{ __('AI-generated') }}
</span>
