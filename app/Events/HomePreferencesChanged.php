<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something the home shelves depend on changed: mature access (`show_mature`, its confirmation), personalization, or the saved preferences.
 */
final class HomePreferencesChanged
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}
}
