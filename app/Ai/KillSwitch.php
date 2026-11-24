<?php

namespace App\Ai;

use Illuminate\Support\Facades\Cache;

class KillSwitch
{
    private const KEY = 'ai:killed';

    /**
     * Stop every model call, on every request and worker, until `resume()`.
     */
    public function kill(): void
    {
        Cache::forever(self::KEY, true);
    }

    public function resume(): void
    {
        Cache::forget(self::KEY);
    }

    public function active(): bool
    {
        return Cache::get(self::KEY, false) === true;
    }
}
