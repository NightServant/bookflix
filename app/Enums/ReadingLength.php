<?php

namespace App\Enums;

enum ReadingLength: string
{
    case Short = 'short';
    case Medium = 'medium';
    case Long = 'long';
    case Any = 'any';
}
