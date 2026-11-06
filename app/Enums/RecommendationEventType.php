<?php

namespace App\Enums;

/**
 * What happened to a recommendation after it was shown; click-through rates are computed from these.
 */
enum RecommendationEventType: string
{
    case Impression = 'impression';
    case Click = 'click';
    case Dismiss = 'dismiss';
    case LibraryAdd = 'library_add';
}
