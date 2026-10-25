<?php

use App\Classification\Dewey\DeweyHierarchy;
use App\Models\DeweyClass;
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
</section>
