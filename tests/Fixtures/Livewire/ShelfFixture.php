<?php

namespace Tests\Fixtures\Livewire;

use App\Models\Media;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * A test-only Livewire component whose shelf starts with one tile and gains the rest on a click, so its track is morphed rather than replaced.
 */
class ShelfFixture extends Component
{
    public bool $more = false;

    /**
     * @return Collection<int, Media>
     */
    #[Computed]
    public function media(): Collection
    {
        return Media::query()->orderBy('id')->limit($this->more ? 14 : 1)->get();
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                <x-shelf title="Staff Picks">
                    @foreach ($this->media as $item)
                        <li wire:key="p-{{ $item->id }}"><x-cover-tile :media="$item" /></li>
                    @endforeach
                </x-shelf>
                <button type="button" wire:click="$set('more', true)">Load more</button>
            </div>
            BLADE;
    }
}
