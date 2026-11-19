{{--
    One Bookflix reader's review on the story page's Reviews tab (<livewire:story-reviews>). The author shows by avatar, name and headline,
    never as a link: there is no profile of anyone else. `own` gives the ⋯ menu Edit and Delete instead of Report, and shows the moderators
    notice on a hidden review (only its author still sees it). A review marked as spoilers stays shut in a <details> until it is opened.
--}}
@php
    $author = $review->user ?? auth()->user();
    $headline = $author->profile?->headline;
@endphp

{{-- One card holds the whole review: avatar, name, headline, date and the review itself. --}}
<article aria-labelledby="review-{{ $review->id }}-author" @class(['rounded-card border border-hairline bg-surface-deep p-5', 'mt-3' => $own])>
    {{-- The avatar is as tall as the name, headline and date beside it: it ends level with "2 days ago". --}}
    <header class="flex items-start gap-3">
        {{-- 60px spans name, headline and date (three lines); 40px spans name and date. --}}
        <flux:avatar :name="$author->name" :initials="$author->initials()" :src="$author->avatarUrl()" @class(['shrink-0 rounded-control!', 'size-15! text-lead!' => filled($headline), 'size-10! text-meta!' => blank($headline)]) />

        <div class="min-w-0 flex-1">
            <p id="review-{{ $review->id }}-author" class="text-meta font-medium text-ink">{{ $author->name }}</p>
            @if (filled($headline))
                <p class="text-legal text-ink-subtle">{{ $headline }}</p>
            @endif
            <p class="text-legal text-ink-muted"><time datetime="{{ $review->created_at->toIso8601String() }}" title="{{ $review->created_at->toFormattedDateString() }}">{{ $review->created_at->diffForHumans() }}</time></p>
        </div>

        @if ($canWrite)
            <flux:dropdown position="bottom" align="end" class="self-start">
                <x-cta variant="secondary" icon="ellipsis-horizontal" :aria-label="$own ? __('Your review options') : __('Review options for :name', ['name' => $author->name])" class="-my-2 -me-2 bg-transparent! hover:bg-surface-card!" />

                <flux:menu class="shadow-overlay!">
                    @if ($own)
                        <flux:menu.item wire:click="edit" icon="pencil-square" class="min-h-11! pe-4!">{{ __('Edit review') }}</flux:menu.item>
                        <flux:menu.item wire:click="delete" wire:confirm="{{ __('Delete your review? This cannot be undone.') }}" icon="trash" variant="danger" class="min-h-11! pe-4!">{{ __('Delete review') }}</flux:menu.item>
                    @else
                        <flux:menu.item wire:click="openReport('review', {{ $review->id }})" x-on:click="reportReturn = $el.closest('ui-dropdown')?.querySelector('button')" icon="flag" class="min-h-11! pe-4!">{{ __('Report review') }}</flux:menu.item>
                    @endif
                </flux:menu>
            </flux:dropdown>
        @endif
    </header>

    @if ($own && $review->status === App\Enums\ReviewState::Hidden)
        <p class="mt-3 inline-flex items-center gap-1.5 text-meta text-ink-subtle"><flux:icon name="eye-slash" variant="micro" class="size-4" />{{ __('A moderator hid this review. Only you can see it.') }}</p>
    @endif

    @if ($review->has_spoilers)
        {{-- Inline in the same card, no box of its own: one line that opens the review in place. --}}
        <details class="group mt-2">
            <summary class="focus-ring inline-flex min-h-11 cursor-pointer list-none flex-wrap items-center gap-x-2 rounded-control text-meta [&::-webkit-details-marker]:hidden">
                <flux:icon name="eye-slash" variant="micro" class="size-4 text-ink-subtle" />
                <span class="text-ink-subtle">{{ __('This review has spoilers.') }}</span>
                <span class="text-link group-open:hidden hover:underline">{{ __('Show review') }}</span>
                <span class="hidden text-link group-open:inline hover:underline">{{ __('Hide review') }}</span>
            </summary>
            <div class="review-body pb-1 text-body text-ink">{{ App\Support\Markdown::safe($review->body) }}</div>
        </details>
    @else
        <div class="review-body mt-3 text-body text-ink">{{ App\Support\Markdown::safe($review->body) }}</div>
    @endif
</article>
