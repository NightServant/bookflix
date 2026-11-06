<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reader dismissed a recommended story; the cached home shelves still hold it until they are forgotten.
 */
final class RecommendationDismissed
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}
}
