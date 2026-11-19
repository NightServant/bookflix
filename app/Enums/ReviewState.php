<?php

namespace App\Enums;

/**
 * Whether a reader's review is shown (P10-R7). Named apart from ReviewStatus, which is the Dewey classification review queue.
 */
enum ReviewState: string
{
    case Published = 'published';
    case Hidden = 'hidden';
}
