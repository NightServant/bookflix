<?php

namespace App\Enums;

enum MediaFamily: string
{
    case Prose = 'prose';
    case SequentialArt = 'sequential_art';
    case Illustrated = 'illustrated';
    case Interactive = 'interactive';
}
