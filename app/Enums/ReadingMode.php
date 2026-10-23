<?php

namespace App\Enums;

enum ReadingMode: string
{
    case Reflowable = 'reflowable';
    case Paged = 'paged';
    case VerticalScroll = 'vertical_scroll';
    case Interactive = 'interactive';
}
