<?php

namespace App\Social;

/**
 * What a followed reader did. The value is the sort rank used to break ties between events of the same moment (see ActivityFeed).
 */
enum ActivityType: int
{
    case FinishedStory = 1;
    case RatedStory = 2;
    case PublishedReview = 3;
    case MadeCollection = 4;
}
