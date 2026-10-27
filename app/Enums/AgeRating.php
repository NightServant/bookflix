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
        $label = match ($this) {
            self::All => 'All ages',
            self::Teen => 'Teen',
            self::Mature => 'Mature',
            self::Adult => 'Adult',
            self::Unknown => null,
        };

        return $label === null ? null : (string) __($label);
    }
}
