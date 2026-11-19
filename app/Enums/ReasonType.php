<?php

namespace App\Enums;

/**
 * Why a title was recommended; the explanation shown to the reader is built from it. The values are stored.
 */
enum ReasonType: string
{
    case BecauseYouRead = 'because_you_read';
    case SimilarThemes = 'similar_themes';
    case PopularWithSimilarReaders = 'popular_with_similar_readers';
    case MatchesInterest = 'matches_interest';
    case Trending = 'trending';
    case Editorial = 'editorial';
    case NewRelease = 'new_release';
    case Exploration = 'exploration';
}
