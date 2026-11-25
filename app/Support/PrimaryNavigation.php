<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * The reader's primary navigation items (config `bookflix.navigation`) that apply right now: an item marked `'requires' => 'ai'` is
 * left out while AI is switched off (none is marked today: Ask is the floating button on Home, P11-R20). The header and the phone bottom bar both read this.
 */
final class PrimaryNavigation
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function items(): Collection
    {
        return Collection::make(config()->array('bookflix.navigation'))
            ->reject(fn (array $item): bool => ($item['requires'] ?? null) === 'ai' && ! config('bookflix.ai.enabled'))
            ->values();
    }
}
