<?php

use App\Classification\ClassShelf;
use App\Classification\ClassShelves;
use App\Classification\Dewey\DeweyHierarchy;
use App\Models\DeweyClass;
use App\Models\Media;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Explore the Library')] class extends Component {
    /**
     * @return Collection<int, DeweyClass>
     */
    #[Computed]
    public function classes(): Collection
    {
        return app(DeweyHierarchy::class)->roots();
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
            ->inDeweySubtree($class)
            ->latest()
            ->latest('id')
            ->limit(12)
            ->get());

        return app(ClassShelves::class)->forMedia($newest->unique('id')->values());
    }
}; ?>

<section class="py-12">
    <h1 class="text-section text-ink">{{ __('Explore the Library') }}</h1>
    <p class="mt-2 max-w-prose text-body text-ink-subtle">{{ __('Browse stories by class, division and section in Bookflix’s Dewey-inspired shelving.') }}</p>

    <h2 class="mt-8 text-lead text-ink">{{ __('Classes') }}</h2>
    <ul class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ($this->classes as $class)
            <li wire:key="class-{{ $class->id }}">
                <x-dewey-class-card :dewey-class="$class" />
            </li>
        @endforeach
    </ul>

    @if ($this->shelves->isNotEmpty())
        <h2 class="mt-12 text-lead text-ink">{{ __('Browse by Subject') }}</h2>
        <div class="mt-4">
            <x-class-shelves :shelves="$this->shelves" />
        </div>
    @endif
</section>
