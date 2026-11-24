<?php

use App\Ai\Assistant\AssistantRunner;
use App\Ai\Assistant\Conversation;
use App\Models\Media;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Ask Bookflix')] class extends Component {
    private const CONVERSATION = 'assistant.conversation';

    private const TRANSCRIPT = 'assistant.transcript';

    /** The most entries the page keeps and shows; the model itself only ever sees the last ten messages (see Conversation). */
    private const MAX_ENTRIES = 20;

    public string $message = '';

    /** The "Use my library" switch of this conversation; it only counts while personalization is on. */
    public bool $useLibrary = false;

    public function mount(): void
    {
        $this->useLibrary = $this->libraryOffered() && $this->conversation()->useLibrary;
    }

    public function updatedUseLibrary(): void
    {
        $conversation = $this->conversation();
        $conversation->useLibrary = $this->useLibrary && $this->libraryOffered();
        session()->put(self::CONVERSATION, $conversation);
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

        $conversation = $this->conversation();
        $conversation->useLibrary = $this->useLibrary && $this->libraryOffered();

        $reply = app(AssistantRunner::class)->reply(auth()->user(), $conversation, $text);

        session()->put(self::CONVERSATION, $conversation);
        session()->put(self::TRANSCRIPT, array_slice([
            ...$this->transcript,
            ['role' => 'user', 'text' => $text, 'ids' => [], 'fallback' => false],
            ['role' => 'assistant', 'text' => $reply->text, 'ids' => $reply->stories->pluck('id')->all(), 'fallback' => $reply->fallback],
        ], -self::MAX_ENTRIES));

        unset($this->transcript, $this->cards);
        $this->message = '';
        $this->dispatch('assistant-answered');
    }

    public function startOver(): void
    {
        session()->forget([self::CONVERSATION, self::TRANSCRIPT]);

        unset($this->transcript, $this->cards);
        $this->message = '';
        $this->useLibrary = false;
        $this->resetErrorBag();
    }

    /**
     * What the page shows, in order: plain text only (escaped when rendered), and for an answer the ids of the verified stories it came with.
     * Kept in the session apart from the Conversation, because a fallback or refused reply is shown but never becomes part of what the model sees.
     *
     * @return list<array{role: 'user'|'assistant', text: string, ids: list<int>, fallback: bool}>
     */
    #[Computed]
    public function transcript(): array
    {
        return session(self::TRANSCRIPT, []);
    }

    /**
     * The stories of the answers, by id, as the reader may see them now (a story merged away or behind the mature gate drops out).
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function cards(): Collection
    {
        $ids = collect($this->transcript)->pluck('ids')->flatten()->unique()->all();

        return $ids === []
            ? new Collection
            : Media::query()->unmerged()->visibleTo(auth()->user())->whereIn('id', $ids)->get()->keyBy('id');
    }

    public function libraryOffered(): bool
    {
        return auth()->user()->personalizationEnabled();
    }

    private function conversation(): Conversation
    {
        $conversation = session(self::CONVERSATION);

        return $conversation instanceof Conversation ? $conversation : new Conversation;
    }
}; ?>

<div class="mx-auto w-full max-w-[720px] pb-6">
    <x-page-header
        :title="__('Ask Bookflix')"
        :description="config('bookflix.ai.enabled') ? __('Say what you feel like reading. Every story it suggests is one in the catalog.') : __('The assistant is switched off.')"
    />

    @if (! config('bookflix.ai.enabled'))
        <div class="mt-8">
            <x-cta variant="secondary" icon="magnifying-glass" :href="route('search')" wire:navigate>{{ __('Go to Search') }}</x-cta>
        </div>
    @else
        @php
            $entries = $this->transcript;
            $cards = $this->cards;
            $chips = [__('Something like Frieren'), __('A short classic for a train ride'), __('Dark fantasy manga with political intrigue')];
            $lastAnswer = collect($entries)->keys()->filter(fn ($key) => $entries[$key]['role'] === 'assistant')->last();
        @endphp

        <div
            x-data="{ pending: '', count: 0 }"
            @assistant-answered.window="pending = ''; count = 0; $nextTick(() => { $refs.input.focus({ preventScroll: true }); document.querySelector('[data-latest-answer]')?.scrollIntoView({ block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); })"
            class="mt-8"
        >
            @if ($entries !== [])
                <div class="flex justify-end">
                    <button
                        type="button"
                        wire:click="startOver"
                        wire:loading.attr="disabled"
                        wire:target="send,ask"
                        class="focus-ring inline-flex min-h-11 items-center gap-2 rounded-control px-2 text-meta text-ink-subtle hover:text-ink"
                    >
                        <flux:icon name="arrow-path" variant="micro" class="size-4" />
                        {{ __('New conversation') }}
                    </button>
                </div>
            @endif

            {{-- The messages: polite, so a new answer is read out once it lands without cutting the reader off. --}}
            <div aria-live="polite" class="flex flex-col gap-8">
                @foreach ($entries as $key => $entry)
                    @if ($entry['role'] === 'user')
                        <div wire:key="entry-{{ $key }}" class="flex justify-end">
                            <p class="max-w-[85%] whitespace-pre-line break-words rounded-card bg-surface-card px-4 py-3 text-body text-ink"><span class="sr-only">{{ __('You:') }} </span>{{ $entry['text'] }}</p>
                        </div>
                    @else
                        @php($stories = collect($entry['ids'])->map(fn ($id) => $cards->get($id))->filter())
                        <div wire:key="entry-{{ $key }}" @if ($key === $lastAnswer) data-latest-answer @endif class="scroll-mt-20">
                            <p class="max-w-prose whitespace-pre-line break-words text-body text-ink text-pretty"><span class="sr-only">{{ __('Bookflix:') }} </span>{{ $entry['text'] }}</p>

                            @if ($stories->isNotEmpty())
                                <ul class="-mx-1 mt-4 flex gap-3 overflow-x-auto p-1 sm:flex-wrap sm:overflow-visible" aria-label="{{ __('Stories from this answer') }}">
                                    @foreach ($stories as $story)
                                        <li wire:key="card-{{ $key }}-{{ $story->id }}" class="shrink-0"><x-cover-tile :media="$story" size="S" /></li>
                                    @endforeach
                                </ul>
                            @endif

                            @if ($entry['fallback'])
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

                @if ($entries === [])
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
