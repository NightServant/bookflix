<?php

namespace App\Enums;

enum ImportOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Merged = 'merged';
    case Skipped = 'skipped';
}
