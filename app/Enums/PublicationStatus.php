<?php

namespace App\Enums;

enum PublicationStatus: string
{
    case Upcoming = 'upcoming';
    case Ongoing = 'ongoing';
    case Completed = 'completed';
    case Hiatus = 'hiatus';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';
}
