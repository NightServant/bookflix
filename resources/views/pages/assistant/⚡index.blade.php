<?php

use App\Ai\Assistant\AssistantMode;
use App\Ai\Assistant\AssistantRunner;
use App\Ai\Assistant\Attachment;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Layout('layouts::assistant'), Title('Ask Bookflix')] class extends Component {
    use WithFileUploads;

    /** The most files one message takes, PDFs included (P11-R26). */
    private const MAX_ATTACHMENTS = 3;

    private const MAX_IMAGE_BYTES = 4 * 1024 * 1024;

    private const MAX_TEXT_BYTES = 200 * 1024;

    /** What a PDF's text is cut to here; the runner caps all attachment text of a message again. */
    private const MAX_PDF_CHARS = AssistantRunner::MAX_ATTACHMENT_TEXT;

    /** The picture types accepted, by the MIME type finfo finds in the bytes (never the client's header or the file name). */
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** The most chats the list shows; older ones are still saved. */
    private const LIST_LIMIT = 100;

    /** The open chat, from the route. Locked, and every use goes through the reader's own chats, so it is never trusted on its own. */
    #[Locked]
    public ?string $conversationId = null;

    public string $message = '';

    /** The task and depth pickers (P11-R25). Plain strings from the client: the runner turns anything unknown into the default. */
    public string $task = 'find';

    public string $depth = 'quick';

    /** Where the browser's file input uploads to; every upload is checked, moved to `$files`, and this is emptied. */
    public $incoming = [];

    /**
     * The attached files waiting for the next send, on Livewire's private temporary disk. Locked: only this component adds to it, and every file
     * is checked again at send. They are deleted right after the reply, or when removed (P11-R26).
     *
     * @var list<TemporaryUploadedFile>
     */
    #[Locked]
    public array $files = [];

    /**
     * PDFs whose text the browser read (the bytes never leave it): name and text.
     *
     * @var list<array{name: string, text: string}>
     */
    #[Locked]
    public array $pdfs = [];

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

        // The pickers start where the last answer left them.
        $last = AssistantMode::parse($chat->messages()->where('role', 'assistant')->whereNotNull('mode')->latest('id')->value('mode'));
        $this->task = $last->task;
        $this->depth = $last->depth;
    }

    public function toggleDepth(): void
    {
        $this->depth = $this->depth === 'thorough' ? 'quick' : 'thorough';
    }

    public function visionOffered(): bool
    {
        return filled(config('bookflix.ai.models.vision'));
    }

    /**
     * Files the browser uploaded: each is checked, and the good ones join the attached files; the rest are deleted with the reason shown.
     */
    public function updatedIncoming(): void
    {
        $this->resetErrorBag('attachments');
        $incoming = is_array($this->incoming) ? $this->incoming : [$this->incoming];
        $this->incoming = [];

        foreach ($incoming as $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            $problem = count($this->files) + count($this->pdfs) >= self::MAX_ATTACHMENTS
                ? __('You can attach up to :count files to a message.', ['count' => self::MAX_ATTACHMENTS])
                : $this->problemWith($file);

            if ($problem !== null) {
                $file->delete();
                $this->addError('attachments', $problem);

                continue;
            }

            $this->files[] = $file;
        }
    }

    /**
     * A PDF's words, read in the browser with pdf.js. They are only text from here on.
     */
    public function addPdfText(string $name, string $text): void
    {
        $this->resetErrorBag('attachments');
        $name = $this->cleanName($name);

        if (count($this->files) + count($this->pdfs) >= self::MAX_ATTACHMENTS) {
            $this->addError('attachments', __('You can attach up to :count files to a message.', ['count' => self::MAX_ATTACHMENTS]));

            return;
        }

        $text = trim(mb_substr(mb_scrub($text), 0, self::MAX_PDF_CHARS));

        if ($text === '') {
            $this->addError('attachments', __(':name has no text Bookflix can read.', ['name' => $name]));

            return;
        }

        $this->pdfs[] = ['name' => $name, 'text' => $text];
    }

    public function removeFile(int $index): void
    {
        ($this->files[$index] ?? null)?->delete();
        unset($this->files[$index]);
        $this->files = array_values($this->files);
        $this->resetErrorBag('attachments');
    }

    public function removePdf(int $index): void
    {
        unset($this->pdfs[$index]);
        $this->pdfs = array_values($this->pdfs);
        $this->resetErrorBag('attachments');
    }

    /**
     * Why a file cannot be attached, or null when it can. The type is what finfo finds in the bytes: a picture only while a vision model is
     * configured, plain text for .txt and .md. A PDF is never uploaded (the browser reads it).
     */
    private function problemWith(TemporaryUploadedFile $file): ?string
    {
        $name = $this->cleanName($file->getClientOriginalName());
        $path = $file->getRealPath();
        $mime = $path === false ? false : (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $size = (int) $file->getSize();
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($mime, self::IMAGE_MIMES, true) && $this->visionOffered()) {
            return $size > self::MAX_IMAGE_BYTES ? __(':name is over 4 MB.', ['name' => $name]) : null;
        }

        if (is_string($mime) && str_starts_with($mime, 'text/') && in_array($extension, ['txt', 'md'], true)) {
            if ($size > self::MAX_TEXT_BYTES) {
                return __(':name is over 200 KB.', ['name' => $name]);
            }

            return mb_check_encoding((string) file_get_contents($path), 'UTF-8') ? null : __(':name is not UTF-8 text.', ['name' => $name]);
        }

        return $this->visionOffered()
            ? __(':name is not a JPG, PNG or WebP picture, or a .txt, .md or PDF file.', ['name' => $name])
            : __(':name is not a .txt, .md or PDF file.', ['name' => $name]);
    }

    private function cleanName(string $name): string
    {
        return mb_substr(trim(preg_replace('/[<>"\x00-\x1F\x7F]+/u', ' ', basename(str_replace('\\', '/', $name))) ?? ''), 0, 100) ?: 'file';
    }

    /**
     * What goes to the model: the checked files read now (not trusted from earlier), and the PDF text.
     *
     * @return list<Attachment>|null null when a file no longer passes
     */
    private function attachmentsToSend(): ?array
    {
        $attachments = [];

        foreach ($this->files as $file) {
            if ($this->problemWith($file) !== null) {
                return null;
            }

            $bytes = (string) file_get_contents($file->getRealPath());
            $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
            $name = $this->cleanName($file->getClientOriginalName());

            $attachments[] = in_array($mime, self::IMAGE_MIMES, true)
                ? new Attachment($name, mime: $mime, base64: base64_encode($bytes))
                : new Attachment($name, text: $bytes);
        }

        foreach ($this->pdfs as $pdf) {
            $attachments[] = new Attachment($pdf['name'], text: $pdf['text']);
        }

        return $attachments;
    }

    /**
     * Delete the temporary files and forget everything attached.
     */
    private function dropAttachments(): void
    {
        foreach ($this->files as $file) {
            $file->delete();
        }

        $this->files = [];
        $this->pdfs = [];
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

        if (! config('bookflix.ai.enabled')) {
            return;
        }

        if ($text === '') {
            if ($this->files !== [] || $this->pdfs !== []) {
                $this->addError('attachments', __('Write a message to go with your files.'));
            }

            return;
        }

        $this->validate(
            ['message' => ['max:'.AssistantRunner::MAX_MESSAGE]],
            ['message.max' => AssistantRunner::TOO_LONG],
        );

        $this->resetErrorBag('attachments');
        $attachments = $this->attachmentsToSend();

        if ($attachments === null) {
            $this->dropAttachments();
            $this->addError('attachments', __('A file could not be used, so it was removed. Attach it again.'));

            return;
        }

        $useLibrary = $this->useLibrary && $this->libraryOffered();
        $isNew = $this->conversationId === null;
        $chat = null;

        try {
            $chat = $isNew
                ? $this->ownChats()->create(['title' => AssistantConversation::titleFor($text), 'use_library' => $useLibrary, 'last_message_at' => now()])
                : $this->openChat();

            $reply = app(AssistantRunner::class)->reply(auth()->user(), $chat, $text, $this->task.':'.$this->depth, $attachments);
        } finally {
            // Whatever happened, the files do not stay: only their names were saved with the message (P11-R26).
            $this->dropAttachments();
        }

        if ($reply->fallback) {
            // The limit or an outage: nothing was saved, so a chat made for this message goes too, and the text stays in the field (P11-R23).
            if ($isNew) {
                $chat->delete();
            }

            $this->addError('message', $reply->text);

            return;
        }

        if ($isNew) {
            // The reply is saved; the chat's own page shows it.
            $this->redirectRoute('assistant.show', ['conversation' => $chat->id], navigate: true);

            return;
        }

        unset($this->messages, $this->cards, $this->groups);
        $this->message = '';
        $this->dispatch('assistant-answered');
    }

    /**
     * @return string|null the reason the title was refused (the row shows it and puts the old title back), or null when it was saved
     */
    public function rename(string $id, string $title): ?string
    {
        $chat = $this->ownChats()->findOrFail($id);
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');

        $validator = Validator::make(['title' => $title], ['title' => ['required', 'string', 'max:'.AssistantConversation::TITLE_MAX]]);

        if ($validator->fails()) {
            $this->addError('title', $validator->errors()->first());

            return $validator->errors()->first();
        }

        $chat->update(['title' => $title]);
        unset($this->groups);

        return null;
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
        $this->dispatch('chat-left');
    }
}; ?>

@php
    $enabled = (bool) config('bookflix.ai.enabled');
@endphp

<div @class(['w-full', 'lg:grid lg:grid-cols-[18rem_minmax(0,1fr)] lg:items-start' => $enabled])>
    @if ($enabled)
        {{-- The app shell's sidebar from the lg breakpoint: the logo, the way back to Home, then the saved chats (New chat first), scrolling on their own. On a phone the History button opens the chats in a drawer. --}}
        <aside aria-label="{{ __('Chat history') }}" class="sticky top-0 hidden h-dvh flex-col overflow-hidden border-e border-hairline bg-surface-deep px-4 py-5 lg:flex">
            <x-app-logo href="{{ route('home') }}" wire:navigate class="self-start" />
            <a href="{{ route('home') }}" wire:navigate data-test="back-home" class="focus-ring mt-3 inline-flex min-h-11 items-center gap-2 rounded-control px-2 text-meta text-ink-subtle hover:text-ink">
                <flux:icon name="arrow-left" variant="micro" class="size-4" />{{ __('Back to Home') }}
            </a>
            <div class="mt-3 min-h-0 flex-1">
                @include('partials.assistant-history', ['scope' => 'side', 'groups' => $this->groups, 'archived' => $this->archivedChats, 'showArchived' => $showArchived, 'currentId' => $conversationId])
            </div>
        </aside>
    @endif

    <div @class(['flex w-full min-w-0 flex-col', 'min-h-dvh' => $enabled])>
        @if ($enabled)
            {{-- Below lg: a slim top bar with the way back to Home, the title, History and New chat. --}}
            <header class="sticky top-0 z-20 flex h-14 items-center gap-2 border-b border-hairline bg-canvas px-2 lg:hidden">
                <a href="{{ route('home') }}" wire:navigate data-test="back-home" aria-label="{{ __('Back to Home') }}" class="focus-ring inline-flex size-11 shrink-0 items-center justify-center rounded-control text-ink hover:bg-surface-card">
                    <flux:icon name="arrow-left" class="size-5" />
                </a>
                <h1 class="min-w-0 flex-1 truncate text-lead text-ink">{{ __('Ask Bookflix') }}</h1>
                <flux:modal.trigger name="assistant-history">
                    <x-cta variant="secondary" icon="clock" data-test="history-open">{{ __('History') }}</x-cta>
                </flux:modal.trigger>
                <x-cta variant="secondary" icon="plus" :href="route('assistant')" wire:navigate :aria-label="__('New chat')" />
            </header>
            <h1 class="sr-only max-lg:hidden">{{ __('Ask Bookflix') }}</h1>
        @else
            <main id="main" tabindex="-1" class="mx-auto w-full max-w-[880px] px-gutter py-8 outline-none">
                <x-app-logo href="{{ route('home') }}" wire:navigate />
                <x-page-header class="mt-10" :title="__('Ask Bookflix')" :description="__('The assistant is switched off.')" />
                <div class="mt-8 flex flex-wrap items-center gap-3">
                    <x-cta variant="secondary" icon="magnifying-glass" :href="route('search')" wire:navigate>{{ __('Go to Search') }}</x-cta>
                    <a href="{{ route('home') }}" wire:navigate data-test="back-home" class="focus-ring inline-flex min-h-11 items-center gap-2 rounded-control px-2 text-meta text-ink-subtle hover:text-ink">
                        <flux:icon name="arrow-left" variant="micro" class="size-4" />{{ __('Back to Home') }}
                    </a>
                </div>
            </main>
        @endif

        @if ($enabled)
            @php
                $entries = $this->messages;
                $cards = $this->cards;
                $chips = [__('Something like Frieren'), __('A short classic for a train ride'), __('Dark fantasy manga with political intrigue')];
                $lastAnswer = $entries->filter(fn ($entry) => $entry->role === 'assistant')->last()?->id;
                $attached = count($files) + count($pdfs);
                // The full label, and the short one a phone shows (the full one stays in the accessible name).
                $tasks = ['find' => [__('Find stories'), __('Find')], 'ask' => [__('Ask about a story'), __('Ask')], 'plan' => [__('Plan my reading'), __('Plan')]];
                $accept = '.txt,.md,.pdf,text/plain,text/markdown,application/pdf'.($this->visionOffered() ? ',image/jpeg,image/png,image/webp' : '');
            @endphp

            {{-- assistantChat (resources/js/assistant-attach.js): the pending text, the character count, `settle` (brings the newest answer into view and, when asked, puts the cursor back in the field) and the attach button. --}}
            <main
                id="main"
                tabindex="-1"
                x-data="assistantChat(@js(['pdfTooBig' => __(':name is over 10 MB.'), 'pdfLocked' => __(':name is password protected.'), 'pdfUnreadable' => __(':name could not be read.'), 'tooBig' => __(':name is too large to attach.'), 'uploadFailed' => __('The upload failed. Try again.')]))"
                x-init="if (@js($entries->isNotEmpty())) settle(innerWidth >= 1024)"
                @assistant-answered.window="pending = ''; count = 0; settle(true)"
                @class(['flex flex-1 flex-col outline-none', 'lg:justify-center' => $entries->isEmpty()])
            >
                @if ($entries->isEmpty())
                    {{-- The welcome band: edge to edge on the featured gradient, in the chat's own column. On a phone it sits under the top bar; from lg the main column centres it with the composer right under it. --}}
                    <div wire:loading.remove wire:target="send,ask" class="bg-linear-to-r from-featured-from via-featured-via to-featured-to px-gutter py-12 lg:px-8 lg:py-16">
                        <div class="mx-auto w-full max-w-[880px]">
                            <h2 class="text-section text-ink">{{ __('What are you in the mood for?') }}</h2>
                            <p class="mt-2 text-body text-ink/80">{{ __('Try one of these, or type your own.') }}</p>

                            <div role="group" aria-label="{{ __('Suggestions') }}" class="mt-6 flex flex-wrap gap-3">
                                @foreach ($chips as $chip)
                                    <button
                                        type="button"
                                        @click="pending = @js($chip)"
                                        wire:click="ask(@js($chip))"
                                        wire:loading.attr="disabled"
                                        wire:target="send,ask"
                                        class="focus-ring min-h-11 rounded-full border border-ink/30 bg-canvas/20 px-4 text-start text-meta text-ink hover:border-ink/60 hover:bg-canvas/40"
                                    >{{ $chip }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif

                <div @class(['px-gutter pb-6 pt-8 lg:px-8', 'flex-1' => $entries->isNotEmpty(), 'max-lg:flex-1 lg:py-0' => $entries->isEmpty()])>
                    <div class="mx-auto w-full max-w-[880px]">
                        {{-- The messages: polite, so a new answer is read out once it lands without cutting the reader off. --}}
                        <div aria-live="polite" class="flex flex-col gap-8">
                            @foreach ($entries as $entry)
                                @if ($entry->role === 'user')
                                    <div wire:key="entry-{{ $entry->id }}" class="flex flex-col items-end gap-2">
                                        <p class="max-w-[85%] whitespace-pre-line break-words rounded-card bg-surface-card px-4 py-3 text-body text-ink lg:max-w-[70ch]"><span class="sr-only">{{ __('You:') }} </span>{{ $entry->text }}</p>

                                        @if ($entry->attachment_names !== [])
                                            <ul class="flex max-w-[85%] flex-wrap justify-end gap-2" aria-label="{{ __('Attached files') }}">
                                                @foreach ($entry->attachment_names as $name)
                                                    <li class="inline-flex min-h-8 max-w-full items-center gap-2 rounded-full border border-hairline px-3 text-legal text-ink-subtle">
                                                        <flux:icon :name="match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) { 'pdf' => 'document-text', 'jpg', 'jpeg', 'png', 'webp' => 'photo', default => 'document' }" variant="micro" class="size-4 shrink-0" />
                                                        <span class="truncate">{{ $name }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>
                                @else
                                    @php($stories = collect($entry->story_ids)->map(fn ($id) => $cards->get($id))->filter())
                                    <div wire:key="entry-{{ $entry->id }}" @if ($entry->id === $lastAnswer) data-latest-answer @endif class="scroll-mt-20">
                                        <p class="max-w-[70ch] whitespace-pre-line break-words text-body text-ink text-pretty"><span class="sr-only">{{ __('Bookflix:') }} </span>{{ $entry->text }}</p>

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
                                    <p class="max-w-[85%] whitespace-pre-line break-words rounded-card bg-surface-card px-4 py-3 text-body text-ink lg:max-w-[70ch]" x-text="pending"></p>
                                </div>
                                <p class="text-body text-ink-subtle">{{ __('Finding stories…') }}</p>
                            </div>

                        </div>
                    </div>
                </div>

                {{-- The input bar: sticks to the foot of the chat area and the conversation scrolls above it. --}}
                <div @class(['sticky bottom-0 z-10 bg-canvas px-gutter pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3 lg:px-8', 'lg:pt-8' => $entries->isEmpty()])>
                  <div class="mx-auto w-full max-w-[880px]">
                    @if ($attached > 0)
                        <ul aria-label="{{ __('Attached files') }}" class="mb-2 flex flex-wrap gap-2">
                            @foreach ($files as $index => $file)
                                <li wire:key="file-{{ $index }}-{{ $file->getFilename() }}" class="inline-flex min-h-11 max-w-full items-center gap-2 rounded-full border border-hairline ps-4 pe-1 text-meta text-ink">
                                    <flux:icon :name="str_starts_with((string) $file->getMimeType(), 'image/') ? 'photo' : 'document'" variant="micro" class="size-4 shrink-0 text-ink-subtle" />
                                    <span class="truncate">{{ $file->getClientOriginalName() }}</span>
                                    <button type="button" wire:click="removeFile({{ $index }})" aria-label="{{ __('Remove :name', ['name' => $file->getClientOriginalName()]) }}" class="focus-ring inline-flex size-11 shrink-0 items-center justify-center rounded-full text-ink-subtle hover:bg-surface-card hover:text-ink">
                                        <flux:icon name="x-mark" variant="micro" class="size-4" />
                                    </button>
                                </li>
                            @endforeach
                            @foreach ($pdfs as $index => $pdf)
                                <li wire:key="pdf-{{ $index }}-{{ md5($pdf['name']) }}" class="inline-flex min-h-11 max-w-full items-center gap-2 rounded-full border border-hairline ps-4 pe-1 text-meta text-ink">
                                    <flux:icon name="document-text" variant="micro" class="size-4 shrink-0 text-ink-subtle" />
                                    <span class="truncate">{{ $pdf['name'] }}</span>
                                    <button type="button" wire:click="removePdf({{ $index }})" aria-label="{{ __('Remove :name', ['name' => $pdf['name']]) }}" class="focus-ring inline-flex size-11 shrink-0 items-center justify-center rounded-full text-ink-subtle hover:bg-surface-card hover:text-ink">
                                        <flux:icon name="x-mark" variant="micro" class="size-4" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <p role="status" class="text-meta text-ink-subtle"><span x-show="reading" style="display: none" class="mb-2 block">{{ __('Reading PDF…') }}</span></p>

                    {{-- The task (a radio group: arrow keys move through it) and the depth (a toggle). --}}
                    <div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-2">
                        <div role="radiogroup" aria-label="{{ __('What to do') }}" class="grid min-w-0 flex-1 grid-cols-3 rounded-control bg-surface-card sm:flex-none">
                            @foreach ($tasks as $value => [$label, $short])
                                <label class="relative block">
                                    <input type="radio" name="assistant-task" value="{{ $value }}" wire:model="task" class="peer sr-only">
                                    <span class="flex min-h-11 cursor-pointer items-center justify-center rounded-control px-3 text-center text-meta leading-tight text-ink-subtle hover:text-ink peer-checked:bg-ink peer-checked:text-canvas peer-checked:hover:text-canvas peer-focus-visible:ring-2 peer-focus-visible:ring-accent-electric peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-canvas"><span class="sr-only sm:hidden">{{ $label }}</span><span aria-hidden="true" class="sm:hidden">{{ $short }}</span><span class="max-sm:hidden">{{ $label }}</span></span>
                                </label>
                            @endforeach
                        </div>

                        <button
                            type="button"
                            wire:click="toggleDepth"
                            aria-pressed="{{ $depth === 'thorough' ? 'true' : 'false' }}"
                            aria-describedby="assistant-depth-hint"
                            data-test="depth-toggle"
                            @class([
                                'focus-ring inline-flex min-h-11 items-center gap-2 rounded-control px-4 text-meta',
                                'bg-ink text-canvas' => $depth === 'thorough',
                                'bg-surface-card text-ink-subtle hover:text-ink' => $depth !== 'thorough',
                            ])
                        >
                            <flux:icon :name="$depth === 'thorough' ? 'check' : 'bolt'" variant="micro" class="size-4" />{{ __('Thorough') }}
                        </button>
                        <span id="assistant-depth-hint" class="sr-only">{{ __('Quick answers fast. Thorough looks deeper and takes longer.') }}</span>
                    </div>

                    <form wire:submit="send" @submit="pending = $refs.input.value.trim()" class="flex gap-3">
                        <input x-ref="picker" type="file" multiple accept="{{ $accept }}" class="hidden" tabindex="-1" aria-hidden="true" @change="pick($event)">
                        <button
                            type="button"
                            @click="$refs.picker.click()"
                            aria-label="{{ __('Attach files') }}"
                            data-test="attach"
                            class="focus-ring inline-flex size-11 shrink-0 items-center justify-center rounded-control border border-border-input bg-surface-input text-ink hover:bg-surface-card"
                        >
                            <flux:icon name="plus" class="size-5" />
                        </button>

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
                            @if ($message === AssistantRunner::UNAVAILABLE)
                                <a href="{{ route('search', array_filter(['q' => trim($this->message)])) }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control px-1 underline underline-offset-4">{{ __('Try Search instead') }}</a>
                            @endif
                        </p>
                    @enderror

                    {{-- Problems with a file, in ink: the server's (a type, a size, too many) and the browser's (a PDF it could not read). --}}
                    @error('attachments')
                        <p role="alert" class="mt-2 flex items-center gap-2 text-meta text-ink">
                            <flux:icon name="exclamation-circle" variant="micro" class="size-4 shrink-0" />{{ $message }}
                        </p>
                    @enderror
                    <p x-show="notice !== ''" style="display: none" role="alert" class="mt-2 flex items-center gap-2 text-meta text-ink">
                        <flux:icon name="exclamation-circle" variant="micro" class="size-4 shrink-0" /><span x-text="notice"></span>
                    </p>

                    <p id="assistant-count" x-show="count >= 1800" style="display: none" class="mt-2 text-end text-legal tabular-nums text-ink-subtle" x-text="count.toLocaleString('en-US') + ' / 2,000'"></p>

                    @if ($this->libraryOffered())
                        <div class="mt-4">
                            <flux:switch wire:model.live="useLibrary" align="left" :label="__('Use my library')" />
                        </div>
                    @endif

                    <p id="assistant-note" class="mt-3 text-legal text-ink-subtle">
                        {{ __('Answers come from an outside AI service.') }}
                        <a href="{{ route('privacy.edit') }}#ai" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control px-1 text-ink underline underline-offset-4">{{ __('What we send') }}</a>
                    </p>
                  </div>
                </div>
            </main>
        @endif
    </div>

    @if ($enabled)
        {{-- The phone's History drawer (a native modal <dialog>: focus is trapped, Escape closes, focus goes back to the History button). --}}
        <div x-data @resize.window.debounce.150ms="innerWidth >= 1024 && $flux.modal('assistant-history').close()" class="lg:hidden">
            <flux:modal name="assistant-history" variant="flyout" position="left" :closable="false" class="w-full max-w-sm text-ink shadow-overlay!">
                <div
                    x-init="$el.closest('dialog').setAttribute('aria-labelledby', 'assistant-history-title')"
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
