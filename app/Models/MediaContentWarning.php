<?php

namespace App\Models;

use App\Enums\WarningStatus;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property WarningStatus $status
 * @property string|null $source The provider key (or "admin") that last asserted the status.
 */
class MediaContentWarning extends Pivot
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WarningStatus::class,
        ];
    }
}
