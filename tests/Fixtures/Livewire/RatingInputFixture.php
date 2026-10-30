<?php

namespace Tests\Fixtures\Livewire;

use Livewire\Component;

/**
 * A test-only Livewire component that binds <x-rating-input> to a score and counts the saves, as the library controls do.
 */
class RatingInputFixture extends Component
{
    public mixed $rating = null;

    public int $saves = 0;

    public function saveRating(): void
    {
        $this->saves++;
    }

    public function clearRating(): void
    {
        $this->rating = null;
        $this->saves++;
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                <x-rating-input wire:model="rating" x-on:rated="$event.detail.value === null ? $wire.clearRating() : $wire.saveRating()" data-test="input" />
                <p>Score <span data-test="model">{{ $rating }}</span>, saves <span data-test="saves">{{ $saves }}</span></p>
                <button type="button" wire:click="$set('rating', 8)">Set eight</button>
                <button type="button">After the stars</button>
            </div>
            BLADE;
    }
}
