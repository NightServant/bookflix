<?php

namespace Tests\Fixtures\Livewire;

use Livewire\Component;

/**
 * A test-only Livewire component that binds <x-range-filter> to a two-value property, for the browser tests.
 */
class RangeFixture extends Component
{
    /** @var list<int> */
    public array $years = [1950, 2000];

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                <x-range-filter label="Publication year" :min="1900" :max="2025" wire:model.live="years" />
                <p data-test="years">{{ implode('-', $years) }}</p>
                <button type="button" wire:click="$refresh">Refresh</button>
                <button type="button" wire:click="$set('years', [1960, 1990])">Narrow</button>
            </div>
            BLADE;
    }
}
