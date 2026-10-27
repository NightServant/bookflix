<?php

use App\Classification\Dewey\DeweyHierarchy;
use App\Classification\DeweyCounts;
use App\Models\DeweyClass;
use App\Models\Media;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Explore the Library')] class extends Component {
    use WithPagination;

    #[Locked]
    public string $code = '';

    public function mount(string $code): void
    {
        $this->code = $code;
    }

    /**
     * The header is a band across the whole page, so the layout leaves `<main>` bare (see layouts/app).
     */
    public function render(): View
    {
        return $this->view()->layoutData(['flush' => true]);
    }

    #[Computed]
    public function deweyClass(): DeweyClass
    {
        return app(DeweyHierarchy::class)->findByCode($this->code) ?? abort(404);
    }

    /**
     * The classes directly below this one that hold a story, each with its `stories_count`; a section such as 813 has none and shows
     * its stories only.
     *
     * @return Collection<int, DeweyClass>
     */
    #[Computed]
    public function children(): Collection
    {
        $counts = app(DeweyCounts::class);

        return app(DeweyHierarchy::class)->children($this->deweyClass)
            ->each(fn (DeweyClass $child) => $child->setAttribute('stories_count', $counts->forClass($child)))
            ->filter(fn (DeweyClass $child): bool => $child->stories_count > 0)
            ->values();
    }

    /**
     * Stories whose public primary class is this class or one below it.
     *
     * @return LengthAwarePaginator<int, Media>
     */
    #[Computed]
    public function stories(): LengthAwarePaginator
    {
        $query = Media::query()->inDeweySubtree($this->deweyClass)->orderBy('title')->orderBy('id');
        $stories = $query->paginate(24);

        if ($stories->isEmpty() && $stories->currentPage() > 1) {
            $this->setPage($stories->lastPage());
            $stories = $query->paginate(24);
        }

        return $stories;
    }

    #[Computed]
    public function levelLabel(): string
    {
        return match ($this->deweyClass->level) {
            1 => __('Class'),
            2 => __('Division'),
            default => __('Section'),
        };
    }

    #[Computed]
    public function childrenLabel(): string
    {
        return $this->deweyClass->level === 1 ? __('Divisions') : __('Sections');
    }
}; ?>

<div class="pb-12">
    <header class="bg-surface-deep px-gutter py-8 lg:py-12">
        <x-dewey-breadcrumb :dewey-class="$this->deweyClass" />

        <x-page-header
            class="mt-4"
            :eyebrow="$this->levelLabel.' · '.__('Dewey-inspired')"
            :title="$this->deweyClass->code.' '.$this->deweyClass->name"
            :description="$this->deweyClass->description ?: ($this->children->isNotEmpty()
                ? __('Stories shelved under :name. Open a section to narrow down, or pick any story below.', ['name' => $this->deweyClass->name])
                : __('Stories shelved under :name. Pick any story below to read more.', ['name' => $this->deweyClass->name]))"
        />
    </header>

    <div class="px-gutter">
        @if ($this->children->isNotEmpty())
            <section aria-labelledby="children-title" class="mt-12 lg:mt-16">
                <h2 id="children-title" class="text-section text-ink">{{ $this->childrenLabel }}</h2>
                <p class="mt-1 text-meta text-ink-subtle">{{ __('Narrower shelves inside :name.', ['name' => $this->deweyClass->name]) }}</p>
                <ul class="mt-4 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-[repeat(auto-fill,minmax(13rem,16rem))]">
                    @foreach ($this->children as $child)
                        <li wire:key="child-{{ $child->id }}">
                            <x-subject-tile :dewey-class="$child" fluid />
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section aria-labelledby="stories-title" class="mt-12 lg:mt-16">
            <h2 id="stories-title" class="text-section text-ink">{{ __('Stories') }}</h2>
            @if ($this->stories->isEmpty())
                <x-empty-state :title="__('No stories here yet')" heading="h3" :book="$this->deweyClass->name" class="mt-6">
                    <p class="mt-2 text-body text-ink-subtle">{{ __('Nothing has been shelved in this class so far. Browse the rest of the Library, or search for a title.') }}</p>

                    <div class="mt-6 flex flex-wrap gap-3">
                        <x-cta variant="secondary" icon="building-library" :href="route('explore.index')" wire:navigate>{{ __('Explore the Library') }}</x-cta>
                    </div>
                </x-empty-state>
            @else
                <ul class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($this->stories as $media)
                        <li wire:key="story-{{ $media->id }}">
                            <x-cover-tile :media="$media" fluid />
                        </li>
                    @endforeach
                </ul>

                <x-pager :paginator="$this->stories" class="mt-8" />
            @endif
        </section>
    </div>
</div>
