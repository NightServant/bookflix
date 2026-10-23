<?php

namespace App\Enums;

enum AgeRating: string
{
    case All = 'all';
    case Teen = 'teen';
    case Mature = 'mature';
    case Adult = 'adult';
    case Unknown = 'unknown';
}
