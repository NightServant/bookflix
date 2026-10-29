<?php

namespace App\Models;

use App\Enums\CreatorRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property CreatorRole $role
 * @property int $position
 */
class MediaCreator extends Pivot
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => CreatorRole::class,
        ];
    }
}
