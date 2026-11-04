<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A profile setting the home shelves depend on changed: mature access (`show_mature`, its confirmation) or personalization.
 */
final class HomePreferencesChanged
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}
}
