{{--
    The assistant's chat list (P11-R24), inside the assistant page component: "New chat", the saved chats grouped by when they last moved
    (Today, Previous 7 days, Older), each with a ⋯ menu (Rename in place, Archive, Delete after a confirm), and an "Archived" view where a chat
    can be restored or deleted. The same markup fills the desktop sidebar and the phone drawer, so `$scope` keeps their keys apart.
    Rename is client-side until it is saved: Enter saves, Escape puts the old title back, and nothing is sent until Enter.
--}}
@php
    $row = function ($chat, bool $archived) use ($scope, $currentId) {
        return compact('chat', 'archived') + ['scope' => $scope, 'current' => ! $archived && $chat->id === $currentId];
    };
@endphp

{{-- `leaving` is set by a row's Archive/Delete click: where focus goes once the row is gone (the next chat, else the one before, else the list's first control). --}}
<div
    x-data="{
        next: null,
        leaving(row) {
            const links = [...row.closest('[data-chat-list]').querySelectorAll('[data-chat-link]')]
            const at = links.indexOf(row.querySelector('[data-chat-link]'))
            this.next = at < 0 ? '' : ((links[at + 1] ?? links[at - 1])?.dataset.chatLink ?? '')
        },
    }"
    @chat-left.window="if (next !== null) { const id = next; next = null; setTimeout(() => (id ? $el.querySelector('[data-chat-link=\'' + id + '\']') : $el.querySelector('[data-list-home]'))?.focus(), 120) }"
    data-chat-list
    class="flex h-full min-h-0 flex-col"
>
    <p id="rename-hint-{{ $scope }}" class="sr-only">{{ __('Press Enter to save the new title, Escape to cancel.') }}</p>

    @if ($showArchived)
        <div class="flex items-center justify-between gap-2">
            <h2 class="text-lead text-ink">{{ __('Archived') }}</h2>
            <button type="button" wire:click="toggleArchived" data-list-home class="focus-ring inline-flex min-h-11 items-center gap-2 rounded-control px-2 text-meta text-ink-subtle hover:text-ink">
                <flux:icon name="arrow-left" variant="micro" class="size-4" />{{ __('Back to chats') }}
            </button>
        </div>
    @else
        <x-cta variant="secondary" icon="plus" :href="route('assistant')" wire:navigate data-list-home class="w-full justify-start">{{ __('New chat') }}</x-cta>
    @endif

    <nav aria-label="{{ $showArchived ? __('Archived chats') : __('Saved chats') }}" class="mt-5 -mx-1 min-h-0 flex-1 overflow-y-auto px-1">
        @if ($showArchived)
            @if ($archived->isEmpty())
                <p class="px-3 text-meta text-ink-subtle">{{ __('Nothing archived.') }}</p>
            @else
                <ul class="flex flex-col gap-0.5">
                    @foreach ($archived as $chat)
                        @include('partials.assistant-history-row', $row($chat, true))
                    @endforeach
                </ul>
            @endif
        @elseif ($groups === [])
            <p class="px-3 text-meta text-ink-subtle">{{ __('Your chats are saved here.') }}</p>
        @else
            @foreach ($groups as $label => $chats)
                <section class="mb-5" aria-labelledby="history-{{ $scope }}-{{ Str::slug($label) }}">
                    <h2 id="history-{{ $scope }}-{{ Str::slug($label) }}" class="px-3 pb-1 text-legal text-ink-subtle">{{ $label }}</h2>
                    <ul class="flex flex-col gap-0.5">
                        @foreach ($chats as $chat)
                            @include('partials.assistant-history-row', $row($chat, false))
                        @endforeach
                    </ul>
                </section>
            @endforeach
        @endif
    </nav>

    @unless ($showArchived)
        <div class="mt-3 shrink-0 border-t border-hairline pt-2">
            <button type="button" wire:click="toggleArchived" class="focus-ring inline-flex min-h-11 w-full items-center gap-2 rounded-control px-3 text-meta text-ink-subtle hover:bg-surface-card hover:text-ink">
                <flux:icon name="archive-box" variant="micro" class="size-4" />{{ __('Archived') }}
            </button>
        </div>
    @endunless
</div>
