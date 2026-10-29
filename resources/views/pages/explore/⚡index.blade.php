<?php

use App\Classification\ClassShelf;
use App\Classification\ClassShelves;
use App\Classification\DeweyCounts;
use App\Models\DeweyClass;
use App\Models\Media;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Explore the Library')] class extends Component {
    /**
     * The bands run edge to edge, so the layout leaves `<main>` bare (see layouts/app).
     */
    public function render(): View
    {
        return $this->view()->layoutData(['flush' => true]);
    }

    /**
     * The main classes that hold a story, each with its `stories_count`: an empty class has no tile and no shelf.
     *
     * @return Collection<int, DeweyClass>
     */
    #[Computed]
    public function classes(): Collection
    {
        return app(DeweyCounts::class)->rootsWithCounts(auth()->user());
    }

    /**
     * The newest 12 stories of each main class, one shelf per class.
     *
     * @return Collection<int, ClassShelf>
     */
    #[Computed]
    public function shelves(): Collection
    {
        $newest = $this->classes->flatMap(fn (DeweyClass $class) => Media::query()
            ->visibleTo(auth()->user())
            ->inDeweySubtree($class)
            ->latest()
            ->latest('id')
            ->limit(12)
            ->get());

        return app(ClassShelves::class)->forMedia($newest->unique('id')->values());
    }
}; ?>

<div class="pb-12">
    <header class="bg-surface-deep px-gutter py-8 lg:py-12">
        <x-page-header :title="__('Explore the Library')" :description="__('Browse stories by class, division and section in Bookflix’s Dewey-inspired shelving. Open a class to go deeper.')" />
    </header>

    <section aria-labelledby="classes-title" class="mt-12 px-gutter lg:mt-16">
        <h2 id="classes-title" class="text-section text-ink">{{ __('Classes') }}</h2>
        <p class="mt-1 text-meta text-ink-subtle">{{ __('The top-level classes that hold stories, 000s to 900s.') }}</p>

        @if ($this->classes->isNotEmpty())
            <ul class="mt-4 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-[repeat(auto-fill,minmax(13rem,16rem))]">
                @foreach ($this->classes as $class)
                    <li wire:key="class-{{ $class->id }}">
                        <x-subject-tile :dewey-class="$class" fluid />
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mt-4 max-w-prose text-body text-ink-subtle">{{ __('Stories are shelved by subject in a Dewey-inspired library. The shelves fill as stories are classified.') }}</p>
        @endif
    </section>

    @if ($this->shelves->isNotEmpty())
        <section aria-labelledby="class-shelves-title" class="mt-12 lg:mt-16">
            <div class="px-gutter">
                <h2 id="class-shelves-title" class="text-section text-ink">{{ __('Browse by Subject') }}</h2>
                <p class="mt-1 text-meta text-ink-subtle">{{ __('The newest stories on each class shelf.') }}</p>
            </div>

            <div class="mt-4 flex flex-col gap-4">
                <x-class-shelves :shelves="$this->shelves" />
            </div>
        </section>
    @endif
</div>
