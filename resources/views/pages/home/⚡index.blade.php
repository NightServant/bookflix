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
        return $this->view()->layoutData(['flush' => true]);
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

        <x-search-panel :action="route('search')" :placeholder="__('Titles, authors, series and subjects')" class="px-gutter py-8 lg:py-12" />

        @foreach (array_keys(App\Discovery\HomeShelves::SHELVES) as $shelf)
            <livewire:home-shelf :$shelf :key="'home-shelf-'.$shelf" />
        @endforeach
    @else
        <div class="px-gutter pt-12">
            <x-empty-catalog heading="h1" />
        </div>
    @endif
</div>
