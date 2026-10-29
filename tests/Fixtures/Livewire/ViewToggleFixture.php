<?php

namespace Tests\Fixtures\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A test-only Livewire component that binds <x-view-toggle> to a property kept in the URL, as the Search and Discover pages do.
 */
class ViewToggleFixture extends Component
{
    #[Url]
    public string $view = 'grid';

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                <x-view-toggle wire:model.live="view" />
                <p data-test="view">{{ $view }}</p>
                <button type="button" wire:click="$set('view', 'list')">Set list</button>
            </div>
            BLADE;
    }
}
