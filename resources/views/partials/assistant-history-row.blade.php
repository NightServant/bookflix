{{--
    One row of the assistant's chat list (see assistant-history). A live chat is a link with Rename / Archive / Delete; an archived one is a
    title with Restore / Delete. Rename swaps the row for a field in place.
--}}
<li
    wire:key="{{ $scope }}-{{ $archived ? 'archived' : 'chat' }}-{{ $chat->id }}"
    x-data="{
        renaming: false,
        title: @js($chat->title),
        saved: @js($chat->title),
        error: '',
        begin() {
            this.renaming = true
            this.error = ''
            // The menu hands focus back to its trigger as it closes; wait that out, then focus the field.
            setTimeout(() => { this.$refs.field.focus(); this.$refs.field.select() }, 60)
        },
        stop(keep) {
            this.renaming = false
            if (! keep) this.title = this.saved
            this.$nextTick(() => this.$refs.link?.focus())
        },
        async save() {
            const next = this.title.trim()
            const before = this.saved
            if (next !== '' && next !== before) {
                this.saved = this.title = next
                this.stop(true)
                // The server answers with why it refused a title; then the old one goes back.
                this.error = (await this.$wire.rename(@js($chat->id), next)) ?? ''
                if (this.error !== '') this.saved = this.title = before
                return
            }
            this.stop(true)
            this.title = this.saved
        },
    }"
>
    <div x-show="! renaming" @class(['flex items-center rounded-control', 'bg-surface-card' => $current, 'hover:bg-surface-card' => ! $current])>
        @if ($archived)
            <p class="min-h-11 min-w-0 flex-1 truncate px-3 py-2.5 text-meta text-ink-subtle" title="{{ $chat->title }}">{{ $chat->title }}</p>
        @else
            <a
                x-ref="link"
                data-chat-link="{{ $chat->id }}"
                href="{{ route('assistant.show', $chat->id) }}"
                wire:navigate
                @if ($current) aria-current="page" @endif
                title="{{ $chat->title }}"
                class="focus-ring min-h-11 min-w-0 flex-1 truncate rounded-control px-3 py-2.5 text-meta text-ink"
            >{{ $chat->title }}</a>
        @endif

        <flux:dropdown position="bottom" align="end">
            <x-cta variant="secondary" icon="ellipsis-horizontal" :aria-label="__('Options for :title', ['title' => $chat->title])" class="bg-transparent! hover:bg-surface-input!" />

            <flux:menu class="shadow-overlay!">
                @if ($archived)
                    <flux:menu.item wire:click="restore('{{ $chat->id }}')" icon="arrow-uturn-left" class="min-h-11! pe-4!">{{ __('Restore') }}</flux:menu.item>
                @else
                    <flux:menu.item x-on:click="begin()" icon="pencil-square" class="min-h-11! pe-4!">{{ __('Rename') }}</flux:menu.item>
                    <flux:menu.item wire:click="archive('{{ $chat->id }}')" x-on:click="leaving($el.closest('li'))" icon="archive-box" class="min-h-11! pe-4!">{{ __('Archive') }}</flux:menu.item>
                @endif

                <flux:menu.separator />

                <flux:menu.item
                    wire:click="delete('{{ $chat->id }}')"
                    x-on:click="leaving($el.closest('li'))"
                    wire:confirm="{{ __('Delete this chat? This can\'t be undone.') }}"
                    icon="trash"
                    variant="danger"
                    class="min-h-11! pe-4!"
                >{{ __('Delete') }}</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </div>

    <p x-show="error !== ''" x-cloak role="alert" x-text="error" class="px-3 pb-1 text-legal text-ink"></p>

    @unless ($archived)
        <form x-show="renaming" x-cloak @submit.prevent="save()" class="px-1 py-0.5">
            <label class="sr-only" for="rename-{{ $scope }}-{{ $chat->id }}">{{ __('Chat title') }}</label>
            <input
                id="rename-{{ $scope }}-{{ $chat->id }}"
                x-ref="field"
                x-model="title"
                @keydown.escape.prevent.stop="stop(false)"
                type="text"
                maxlength="120"
                autocomplete="off"
                aria-describedby="rename-hint-{{ $scope }}"
                class="focus-ring h-11 w-full min-w-0 rounded-control border border-border-input bg-surface-input px-3 text-meta text-ink"
            >
        </form>
    @endunless
</li>
