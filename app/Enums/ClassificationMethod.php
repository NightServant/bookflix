<?php

namespace App\Enums;

enum ClassificationMethod: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
    case External = 'external';
}
