<?php

namespace App\Enums;

enum AgeRating: string
{
    case All = 'all';
    case Teen = 'teen';
    case Mature = 'mature';
    case Adult = 'adult';
    case Unknown = 'unknown';

    /**
     * The reader-facing label; null when the rating is unknown, so nothing is shown.
     */
    public function label(): ?string
    {
        return match ($this) {
            self::All => __('All ages'),
            self::Teen => __('Teen'),
            self::Mature => __('Mature'),
            self::Adult => __('Adult'),
            self::Unknown => null,
        };
    }
}
