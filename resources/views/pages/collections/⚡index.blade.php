<?php

use App\Actions\Library\CreateCollection;
use App\Discovery\CollectionPreviews;
use App\Discovery\EditorialCollections;
use App\Models\Collection;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Collections')] class extends Component {
    public string $name = '';

    /**
     * The header is a band across the whole page, so the layout leaves `<main>` bare (see layouts/app).
     */
    public function render(): View
    {
        return $this->view()->layoutData(['flush' => true]);
    }

    /**
     * Start a collection and open it, so the next thing the reader does is add stories.
     */
    public function create(): void
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->hasVerifiedEmail(), 403);

        $this->validate(['name' => ['required', 'string', 'max:100']], attributes: ['name' => __('name')]);

        $collection = app(CreateCollection::class)->handle($user, trim($this->name));
        $this->reset('name');

        $this->redirectRoute('collections.show', $collection->slug, navigate: true);
    }

    /**
     * The reader's own collections, the most recently changed first.
     *
     * @return EloquentCollection<int, Collection>
     */
    #[Computed]
    public function mine(): EloquentCollection
    {
        return auth()->user()->collections()->orderByDesc('updated_at')->orderByDesc('id')->get();
    }

    /**
     * The staff picks the reader may open (those with a story they may see) that are not their own, for the section under their own.
     *
     * @return EloquentCollection<int, Collection>
     */
    #[Computed]
    public function editorial(): EloquentCollection
    {
        $user = auth()->user();

        return app(EditorialCollections::class)->collections($user)->reject(fn (Collection $collection): bool => $collection->user_id === $user->id)->values();
    }

    /**
     * What each row shows: the stories the reader may see and the first covers, for both lists in two queries.
     *
     * @return array<int, array{count: int, covers: list<string>}>
     */
    #[Computed]
    public function previews(): array
    {
        return app(CollectionPreviews::class)->for($this->mine->merge($this->editorial), auth()->user());
    }
}; ?>

<div class="pb-12">
    <header class="bg-surface-deep px-gutter py-8 lg:py-12">
        <x-page-header :title="__('Collections')" :description="__('Lists of your own: a reading list, a mood, a theme. Name one to start, then add stories from its page or from any story.')" />

        <x-library-tabs current="collections.index" class="mt-6" />
    </header>

    <div class="px-gutter">
        <form wire:submit="create" class="mt-8 max-w-xl" aria-labelledby="new-collection-title">
            <h2 id="new-collection-title" class="text-lead text-ink">{{ __('New Collection') }}</h2>

            <div class="mt-3 flex flex-wrap items-start gap-3">
                <div class="min-w-0 flex-1 basis-60">
                    <label for="collection-name" class="sr-only">{{ __('Collection name') }}</label>
                    <input
                        id="collection-name"
                        type="text"
                        wire:model="name"
                        maxlength="100"
                        autocomplete="off"
                        placeholder="{{ __('Rainy day reads') }}"
                        aria-describedby="collection-name-error"
                        class="focus-ring h-11 w-full rounded-control border border-border-input bg-surface-input px-3 text-body text-ink placeholder:text-ink-muted"
                    >
                </div>

                <x-cta type="submit" icon="plus" wire:loading.attr="disabled" wire:target="create">{{ __('Create collection') }}</x-cta>
            </div>

            <div id="collection-name-error" role="alert"><flux:error name="name" class="mt-2" /></div>
        </form>

        <section aria-labelledby="mine-title" class="mt-12 lg:mt-16">
            <h2 id="mine-title" class="text-section text-ink">{{ __('Your Collections') }}</h2>

            @if ($this->mine->isNotEmpty())
                <ul class="mt-4 divide-y divide-hairline border-y border-hairline">
                    @foreach ($this->mine as $collection)
                        <li wire:key="collection-{{ $collection->id }}">
                            <x-collection-row :collection="$collection" :preview="$this->previews[$collection->id]" />
                        </li>
                    @endforeach
                </ul>
            @else
                <x-empty-state :title="__('No collections yet')" heading="h3" class="mt-6">
                    <p class="mt-2 text-body text-ink-subtle">{{ __('A collection is a list you make yourself. Name one above, then add stories from its page, or use the collection button on any story.') }}</p>

                    <div class="mt-6 flex flex-wrap gap-3">
                        <x-cta variant="secondary" icon="compass" :href="route('discover')" wire:navigate>{{ __('Browse Discover') }}</x-cta>
                    </div>
                </x-empty-state>
            @endif
        </section>

        @if ($this->editorial->isNotEmpty())
            <section aria-labelledby="editorial-title" class="mt-12 lg:mt-16">
                <h2 id="editorial-title" class="text-section text-ink">{{ __('Picked by Our Editors') }}</h2>
                <p class="mt-1 text-meta text-ink-subtle">{{ __('Themed lists from the Bookflix editors. Open one to see what is in it.') }}</p>

                <ul class="mt-4 divide-y divide-hairline border-y border-hairline">
                    @foreach ($this->editorial as $collection)
                        <li wire:key="editorial-{{ $collection->id }}">
                            <x-collection-row :collection="$collection" :preview="$this->previews[$collection->id]" editorial />
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</div>
