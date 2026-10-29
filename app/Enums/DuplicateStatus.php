<?php

namespace App\Enums;

enum DuplicateStatus: string
{
    case Pending = 'pending';
    case Merged = 'merged';
    case Dismissed = 'dismissed';
}
