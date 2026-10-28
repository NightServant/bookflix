<?php

namespace App\Enums;

/**
 * The audience rating of a work, separate from its editorial demographic (a shonen title is not automatically teen).
 * Cases run from the youngest audience up; Unknown is not a rating and is never filterable.
 */
enum AgeRating: string
{
    case All = 'all';
    case Children = 'children';
    case Teen = 'teen';
    case MatureTeen = 'mature_teen';
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
            self::Children => 'Children',
            self::Teen => 'Teen',
            self::MatureTeen => 'Mature teen',
            self::Mature => 'Mature',
            self::Adult => 'Adult (18+)',
            self::Unknown => null,
        };

        return $label === null ? null : (string) __($label);
    }

    /**
     * The ratings a reader can filter by: every case but Unknown.
     *
     * @return list<self>
     */
    public static function filterable(): array
    {
        return [self::All, self::Children, self::Teen, self::MatureTeen, self::Mature, self::Adult];
    }
}
