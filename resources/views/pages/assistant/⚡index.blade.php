<?php

use App\Ai\Assistant\AssistantRunner;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Ask Bookflix')] class extends Component {
    /** The most chats the list shows; older ones are still saved. */
    private const LIST_LIMIT = 100;

    /** The open chat, from the route. Locked, and every use goes through the reader's own chats, so it is never trusted on its own. */
    #[Locked]
    public ?string $conversationId = null;

    public string $message = '';

    /** The "Use my library" switch of this chat; it only counts while personalization is on. */
    public bool $useLibrary = false;

    /** Whether the chat list shows the archived chats. */
    public bool $showArchived = false;

    public function mount(?string $conversation = null): void
    {
        // With AI off the page is a notice and a link; no chat is looked up (P11-R17, P11-R20).
        if ($conversation === null || ! config('bookflix.ai.enabled')) {
            return;
        }

        $chat = $this->ownChats()->active()->findOrFail($conversation);

        $this->conversationId = $chat->id;
        $this->useLibrary = $this->libraryOffered() && $chat->use_library;
    }

    public function updatedUseLibrary(): void
    {
        if ($this->conversationId !== null) {
            $this->openChat()->update(['use_library' => $this->useLibrary && $this->libraryOffered()]);
        }
    }

    /**
     * A suggestion chip: it sends its own text.
     */
    public function ask(string $text): void
    {
        $this->message = $text;
        $this->send();
    }

    public function send(): void
    {
        $text = trim($this->message);

        if ($text === '' || ! config('bookflix.ai.enabled')) {
            return;
        }

        $this->validate(
            ['message' => ['max:'.AssistantRunner::MAX_MESSAGE]],
            ['message.max' => AssistantRunner::TOO_LONG],
        );

        $useLibrary = $this->useLibrary && $this->libraryOffered();
        $isNew = $this->conversationId === null;
        $chat = $isNew
            ? $this->ownChats()->create(['title' => AssistantConversation::titleFor($text), 'use_library' => $useLibrary, 'last_message_at' => now()])
            : $this->openChat();

        app(AssistantRunner::class)->reply(auth()->user(), $chat, $text);

        if ($isNew) {
            // The reply is saved; the chat's own page shows it.
            $this->redirectRoute('assistant.show', ['conversation' => $chat->id], navigate: true);

            return;
        }

        unset($this->messages, $this->cards, $this->groups);
        $this->message = '';
        $this->dispatch('assistant-answered');
    }

    public function rename(string $id, string $title): void
    {
        $chat = $this->ownChats()->findOrFail($id);
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');

        Validator::make(['title' => $title], ['title' => ['required', 'string', 'max:'.AssistantConversation::TITLE_MAX]])->validate();

        $chat->update(['title' => $title]);
        unset($this->groups);
    }

    public function archive(string $id): void
    {
        $this->ownChats()->findOrFail($id)->update(['archived_at' => now()]);

        $this->afterLeaving($id);
    }

    public function restore(string $id): void
    {
        $this->ownChats()->archived()->findOrFail($id)->update(['archived_at' => null]);

        unset($this->groups, $this->archivedChats);
    }

    public function delete(string $id): void
    {
        $this->ownChats()->findOrFail($id)->delete();

        $this->afterLeaving($id);
    }

    public function toggleArchived(): void
    {
        $this->showArchived = ! $this->showArchived;
    }

    /**
     * The reader's chats, grouped for the list: Today, Previous 7 days, Older (by the last message). Empty groups are left out.
     *
     * @return array<string, Collection<int, AssistantConversation>>
     */
    #[Computed]
    public function groups(): array
    {
        if (! config('bookflix.ai.enabled')) {
            return [];
        }

        $today = now()->startOfDay();
        $week = $today->copy()->subDays(7);

        $chats = $this->ownChats()->active()->orderByDesc('last_message_at')->orderByDesc('created_at')->limit(self::LIST_LIMIT)->get(['id', 'title', 'last_message_at', 'created_at']);

        $groups = [
            __('Today') => $chats->filter(fn (AssistantConversation $chat): bool => $chat->last_message_at >= $today),
            __('Previous 7 days') => $chats->filter(fn (AssistantConversation $chat): bool => $chat->last_message_at < $today && $chat->last_message_at >= $week),
            __('Older') => $chats->filter(fn (AssistantConversation $chat): bool => $chat->last_message_at < $week),
        ];

        return array_filter($groups, fn (Collection $group): bool => $group->isNotEmpty());
    }

    /**
     * @return Collection<int, AssistantConversation>
     */
    #[Computed]
    public function archivedChats(): Collection
    {
        return $this->showArchived
            ? $this->ownChats()->archived()->orderByDesc('archived_at')->limit(self::LIST_LIMIT)->get(['id', 'title'])
            : new Collection;
    }

    /**
     * What the page shows, in order: plain text only (escaped when rendered), and for an answer the ids of the verified stories it came with.
     *
     * @return Collection<int, AssistantMessage>
     */
    #[Computed]
    public function messages(): Collection
    {
        return $this->conversationId === null ? new Collection : $this->openChat()->messages()->get();
    }

    /**
     * The stories of the answers, by id, as the reader may see them now (a story merged away or behind the mature gate drops out).
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function cards(): Collection
    {
        $ids = $this->messages->pluck('story_ids')->flatten()->unique()->all();

        return $ids === []
            ? new Collection
            : Media::query()->unmerged()->visibleTo(auth()->user())->whereIn('id', $ids)->get()->keyBy('id');
    }

    public function libraryOffered(): bool
    {
        return auth()->user()->personalizationEnabled();
    }

    /**
     * The only door to a chat: the reader's own, so another reader's id is a 404.
     *
     * @return HasMany<AssistantConversation, User>
     */
    private function ownChats(): HasMany
    {
        return auth()->user()->assistantConversations();
    }

    private function openChat(): AssistantConversation
    {
        return $this->ownChats()->active()->findOrFail($this->conversationId);
    }

    /**
     * After a chat is archived or deleted: leave its page if it was the open one, else refresh the list.
     */
    private function afterLeaving(string $id): void
    {
        if ($id === $this->conversationId) {
            $this->redirectRoute('assistant', navigate: true);

            return;
        }

        unset($this->groups, $this->archivedChats);
    }
}; ?>

@php
    $enabled = (bool) config('bookflix.ai.enabled');
@endphp

<div @class(['w-full', 'lg:grid lg:grid-cols-[17.5rem_minmax(0,1fr)] lg:items-start lg:gap-10' => $enabled])>
    @if ($enabled)
        {{-- The saved chats: a column beside the chat from the lg breakpoint; on a phone the History button opens them in a drawer. --}}
        <aside aria-label="{{ __('Chat history') }}" class="sticky top-20 hidden h-[calc(100dvh-7rem)] lg:block">
            @include('partials.assistant-history', ['scope' => 'side', 'groups' => $this->groups, 'archived' => $this->archivedChats, 'showArchived' => $showArchived, 'currentId' => $conversationId])
        </aside>
    @endif

    <div class="mx-auto w-full min-w-0 max-w-[720px] pb-6">
        <x-page-header
            :title="__('Ask Bookflix')"
            :description="$enabled ? __('Say what you feel like reading. Every story it suggests is one in the catalog.') : __('The assistant is switched off.')"
        >
            @if ($enabled)
                <x-slot:actions>
                    <div class="flex items-center gap-3 lg:hidden">
                        <flux:modal.trigger name="assistant-history">
                            <x-cta variant="secondary" icon="clock" data-test="history-open">{{ __('History') }}</x-cta>
                        </flux:modal.trigger>
                        <x-cta variant="secondary" icon="plus" :href="route('assistant')" wire:navigate :aria-label="__('New chat')" />
                    </div>
                </x-slot:actions>
            @endif
        </x-page-header>

        @if (! $enabled)
            <div class="mt-8">
                <x-cta variant="secondary" icon="magnifying-glass" :href="route('search')" wire:navigate>{{ __('Go to Search') }}</x-cta>
            </div>
        @else
            @php
                $entries = $this->messages;
                $cards = $this->cards;
                $chips = [__('Something like Frieren'), __('A short classic for a train ride'), __('Dark fantasy manga with political intrigue')];
                $lastAnswer = $entries->filter(fn ($entry) => $entry->role === 'assistant')->last()?->id;
            @endphp

            {{-- `settle` brings the newest answer into view and (when asked, or on a desktop) puts the cursor back in the field. --}}
            <div
                x-data="{
                    pending: '',
                    count: 0,
                    settle(focus) {
                        this.$nextTick(() => {
                            if (focus) this.$refs.input.focus({ preventScroll: true })
                            document.querySelector('[data-latest-answer]')?.scrollIntoView({ block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })
                        })
                    },
                }"
                x-init="if (@js($entries->isNotEmpty())) settle(innerWidth >= 1024)"
                @assistant-answered.window="pending = ''; count = 0; settle(true)"
                class="mt-8"
            >
                {{-- The messages: polite, so a new answer is read out once it lands without cutting the reader off. --}}
                <div aria-live="polite" class="flex flex-col gap-8">
                    @foreach ($entries as $entry)
                        @if ($entry->role === 'user')
                            <div wire:key="entry-{{ $entry->id }}" class="flex justify-end">
                                <p class="max-w-[85%] whitespace-pre-line break-words rounded-card bg-surface-card px-4 py-3 text-body text-ink"><span class="sr-only">{{ __('You:') }} </span>{{ $entry->text }}</p>
                            </div>
                        @else
                            @php($stories = collect($entry->story_ids)->map(fn ($id) => $cards->get($id))->filter())
                            <div wire:key="entry-{{ $entry->id }}" @if ($entry->id === $lastAnswer) data-latest-answer @endif class="scroll-mt-20">
                                <p class="max-w-prose whitespace-pre-line break-words text-body text-ink text-pretty"><span class="sr-only">{{ __('Bookflix:') }} </span>{{ $entry->text }}</p>

                                @if ($stories->isNotEmpty())
                                    <ul class="-mx-1 mt-4 flex gap-3 overflow-x-auto p-1 sm:flex-wrap sm:overflow-visible" aria-label="{{ __('Stories from this answer') }}">
                                        @foreach ($stories as $story)
                                            <li wire:key="card-{{ $entry->id }}-{{ $story->id }}" class="shrink-0"><x-cover-tile :media="$story" size="S" /></li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($entry->fixed)
                                    <p class="mt-3"><a href="{{ route('search') }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center gap-2 rounded-control text-meta text-ink underline underline-offset-4">
                                        <flux:icon name="magnifying-glass" variant="micro" class="size-4" />{{ __('Go to Search') }}
                                    </a></p>
                                @endif
                            </div>
                        @endif
                    @endforeach

                    {{-- Shown from the moment a message is sent until the answer is in (Send is disabled meanwhile). --}}
                    <div wire:loading.flex wire:target="send,ask" class="flex-col gap-8">
                        <div x-show="pending !== ''" class="flex justify-end">
                            <p class="max-w-[85%] whitespace-pre-line break-words rounded-card bg-surface-card px-4 py-3 text-body text-ink" x-text="pending"></p>
                        </div>
                        <p class="text-body text-ink-subtle">{{ __('Finding stories…') }}</p>
                    </div>

                    @if ($entries->isEmpty())
                        <div wire:loading.remove wire:target="send,ask" class="pt-4">
                            <h2 class="text-lead text-ink">{{ __('What are you in the mood for?') }}</h2>
                            <p class="mt-2 text-body text-ink-subtle">{{ __('Try one of these, or type your own.') }}</p>

                            <div role="group" aria-label="{{ __('Suggestions') }}" class="mt-5 flex flex-wrap gap-3">
                                @foreach ($chips as $chip)
                                    <button
                                        type="button"
                                        @click="pending = @js($chip)"
                                        wire:click="ask(@js($chip))"
                                        wire:loading.attr="disabled"
                                        wire:target="send,ask"
                                        class="focus-ring min-h-11 rounded-full border border-hairline px-4 text-start text-meta text-ink hover:bg-surface-card"
                                    >{{ $chip }}</button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Sticks above the phone's bottom bar (the bar is 4.75rem high), at the foot of the column on a desktop. --}}
                <div class="sticky bottom-[4.75rem] z-10 mt-8 bg-canvas pb-3 pt-3 lg:bottom-0">
                    <form wire:submit="send" @submit="pending = $refs.input.value.trim()" class="flex gap-3">
                        <label for="assistant-message" class="sr-only">{{ __('Your message') }}</label>
                        <input
                            id="assistant-message"
                            x-ref="input"
                            type="text"
                            wire:model="message"
                            @input="count = $event.target.value.length"
                            autocomplete="off"
                            enterkeyhint="send"
                            placeholder="{{ __('Ask for a story') }}"
                            aria-describedby="assistant-note assistant-count"
                            @error('message') aria-invalid="true" @enderror
                            class="focus-ring h-11 min-w-0 flex-1 rounded-control border border-border-input bg-surface-input px-3 text-body text-ink placeholder:text-ink-subtle"
                        >
                        <x-cta type="submit" wire:loading.attr="disabled" wire:target="send,ask">{{ __('Send') }}</x-cta>
                    </form>

                    @error('message')
                        <p role="alert" class="mt-2 flex items-center gap-2 text-meta text-ink">
                            <flux:icon name="exclamation-circle" variant="micro" class="size-4 shrink-0" />{{ $message }}
                        </p>
                    @enderror

                    <p id="assistant-count" x-show="count >= 1800" style="display: none" class="mt-2 text-end text-legal tabular-nums text-ink-subtle" x-text="count.toLocaleString('en-US') + ' / 2,000'"></p>

                    @if ($this->libraryOffered())
                        <flux:switch wire:model.live="useLibrary" align="left" :label="__('Use my library')" class="mt-4" />
                    @endif

                    <p id="assistant-note" class="mt-3 text-legal text-ink-subtle">
                        {{ __('Answers come from an outside AI service.') }}
                        <a href="{{ route('privacy.edit') }}#ai" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control px-1 text-ink underline underline-offset-4">{{ __('What we send') }}</a>
                    </p>
                </div>
            </div>
        @endif
    </div>

    @if ($enabled)
        {{-- The phone's History drawer (a native modal <dialog>: focus is trapped, Escape closes, focus goes back to the History button). --}}
        <div x-data @resize.window.debounce.150ms="innerWidth >= 1024 && $flux.modal('assistant-history').close()" class="lg:hidden">
            <flux:modal name="assistant-history" variant="flyout" position="left" :closable="false" class="w-full max-w-sm text-ink shadow-overlay!">
                <div
                    x-init="$el.closest('dialog').setAttribute('aria-labelledby', 'assistant-history-title')"
                    @keydown.tab.prevent="$focus.wrap().next()"
                    @keydown.shift.tab.prevent="$focus.wrap().previous()"
                    class="flex h-[calc(100dvh-4rem)] flex-col gap-4"
                >
                    <div class="flex items-center justify-between gap-4">
                        <h2 id="assistant-history-title" class="text-lead text-ink">{{ __('Chat history') }}</h2>

                        <flux:modal.close>
                            <button type="button" autofocus aria-label="{{ __('Close history') }}" class="focus-ring inline-flex size-11 items-center justify-center rounded-control text-ink-subtle hover:bg-surface-input hover:text-ink">
                                <flux:icon name="x-mark" class="size-5" />
                            </button>
                        </flux:modal.close>
                    </div>

                    <div class="min-h-0 flex-1">
                        @include('partials.assistant-history', ['scope' => 'drawer', 'groups' => $this->groups, 'archived' => $this->archivedChats, 'showArchived' => $showArchived, 'currentId' => $conversationId])
                    </div>
                </div>
            </flux:modal>
        </div>
    @endif
</div>
