<?php

namespace App\Classification\Dewey;

use App\Models\DeweyClass;

class DeweyResolver
{
    /**
     * Decimal places looked at; a longer code only ever lands on a seeded prefix anyway.
     */
    private const MAX_DECIMALS = 6;

    /**
     * The deepest seeded class that an external code starts with, e.g. 813.54 gives 813. Anything that is not
     * three digits and an optional decimal part (`""`, `abc`, `813.`, `999.99` when unseeded) gives null.
     * Bookflix splits 741.5 by country of origin, which external notation does not follow, so every 741.5x code resolves to 741.5.
     */
    public function resolve(string $externalCode): ?DeweyClass
    {
        $code = trim($externalCode);

        if (preg_match('/^\d{3}(?:\.\d+)?$/D', $code) !== 1) {
            return null;
        }

        if (str_starts_with($code, '741.5')) {
            $code = '741.5';
        }

        [$whole, $decimals] = array_pad(explode('.', $code, 2), 2, '');
        $decimals = substr($decimals, 0, self::MAX_DECIMALS);
        $prefixes = [$whole];

        for ($length = 1; $length <= strlen($decimals); $length++) {
            $prefixes[] = "{$whole}.".substr($decimals, 0, $length);
        }

        return DeweyClass::whereIn('code', $prefixes)->orderByRaw('length(code) desc')->first();
    }
}
