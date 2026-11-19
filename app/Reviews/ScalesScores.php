<?php

namespace App\Reviews;

/**
 * Shared by the providers that offer reviews (they also use SanitizesProviderData): scores onto 0 to 100, and counts and links that are safe to store.
 */
trait ScalesScores
{
    /**
     * @param  float|int|string|null  $value  a score on a 0 to $max scale
     */
    protected function scale(mixed $value, float $max, bool $zeroMeansNone = true): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        if ($value < 0 || $value > $max || ($zeroMeansNone && $value == 0)) {
            return null;
        }

        return (int) round($value / $max * 100);
    }

    protected function count(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * An https link on this provider's allowed hosts that fits the column, else null.
     */
    protected function storableUrl(mixed $url): ?string
    {
        $url = $this->safeUrl($url);

        return $url !== null && strlen($url) <= 2048 ? $url : null;
    }
}
