<?php

namespace App\Enums;

/**
 * The signals the recommender learns from (spec §44). The names are the stored values, so they never change.
 */
enum InteractionEvent: string
{
    case Search = 'search';
    case View = 'view';
    case Click = 'click';
    case LibraryAdd = 'library_add';
    case ReadingStart = 'reading_start';
    case ReadingProgress = 'reading_progress';
    case ReadingComplete = 'reading_complete';
    case Favorite = 'favorite';
    case Rating = 'rating';
    case Review = 'review';
    case Abandon = 'abandon';
    case RecommendationClick = 'recommendation_click';
}
