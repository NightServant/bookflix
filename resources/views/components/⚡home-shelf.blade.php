<?php

use App\Discovery\HomeShelves;
use App\Discovery\Shelf;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One of the guest-safe shelves of the home screen (a `HomeShelves::SHELVES` key), loaded when it scrolls into view.
 * Until then the page holds <x-shelf-skeleton>, the same size, so the load shifts nothing.
 */
new #[Lazy] class extends Component {
    #[Locked]
    public string $shelf = '';

    /**
     * @param  array<string, mixed>  $params
     */
    public function placeholder(array $params = []): View
    {
        $key = (string) ($params['shelf'] ?? '');
        $definition = HomeShelves::SHELVES[$key] ?? ['title' => '', 'featured' => false];

        return view('components.shelf-skeleton', [
            'key' => $key,
            'title' => __($definition['title']),
            'description' => isset($definition['description']) ? __($definition['description']) : null,
            'featured' => $definition['featured'],
            'kind' => $key === 'explore-by-subject' ? 'subjects' : 'covers',
            'bleed' => true,
        ]);
    }

    #[Computed]
    public function current(): Shelf
    {
        return app(HomeShelves::class)->shelf($this->shelf, viewer: auth()->user());
    }
}; ?>

<div>
    @if ($this->current->items->isNotEmpty())
        @php
            $shelf = $this->current;
            $seeAll = $shelf->key === 'explore-by-subject' ? route('explore.index') : route('discover');
            $description = HomeShelves::SHELVES[$shelf->key]['description'] ?? null;
        @endphp

        <x-shelf :title="$shelf->title" :href="$seeAll" :featured="$shelf->featured" :id="'shelf-'.$shelf->key" :eyebrow="$shelf->reasonLabel" :description="$description ? __($description) : null" :previews="$shelf->key === 'explore-by-subject' ? null : 'quick-view-'.$shelf->key" bleed>
            @foreach ($shelf->items as $item)
                <li wire:key="{{ $shelf->key }}-{{ $item->id }}">
                    @if ($shelf->key === 'explore-by-subject')
                        <x-subject-tile :dewey-class="$item" />
                    @else
                        <x-story-preview :media="$item">
                            <x-cover-tile :media="$item" />
                        </x-story-preview>
                    @endif
                </li>
            @endforeach
        </x-shelf>
    @endif
</div>
