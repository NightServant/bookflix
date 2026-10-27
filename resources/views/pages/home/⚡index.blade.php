<?php

use App\Discovery\HomeShelves;
use App\Models\Media;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Home')] class extends Component {
    /**
     * Home builds its own full-width bands (the hero, the shelves), so the layout leaves `<main>` bare.
     */
    public function render(): View
    {
        return $this->view()->layoutData(['flush' => true, 'band' => $this->slides->isNotEmpty()]);
    }

    #[Computed]
    public function hasCatalog(): bool
    {
        return Media::query()->unmerged()->exists();
    }

    /**
     * The five most trending stories that have a cover, for the hero.
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function slides(): Collection
    {
        return app(HomeShelves::class)->hero();
    }
}; ?>

<div>
    @if ($this->hasCatalog)
        @if ($this->slides->isNotEmpty())
            <x-hero :slides="$this->slides" bleed />
        @else
            <h1 class="sr-only">{{ __('Home') }}</h1>
        @endif

        <x-search-panel :action="route('search')" :placeholder="__('Titles, authors, series and subjects')" class="my-12 px-gutter lg:my-16" />

        @foreach (array_keys(App\Discovery\HomeShelves::SHELVES) as $shelf)
            {{-- The shelves are bands that butt together: each brings its own padding, so there is no strip of bare canvas between two of them. --}}
            <div>
                <livewire:home-shelf :$shelf :key="'home-shelf-'.$shelf" />
            </div>
        @endforeach
    @else
        <div class="px-gutter pt-12">
            <x-empty-catalog heading="h1" />
        </div>
    @endif
</div>
