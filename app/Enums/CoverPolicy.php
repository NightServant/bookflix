<?php

namespace App\Enums;

enum CoverPolicy: string
{
    case Hotlink = 'hotlink';
    case Cache = 'cache';
    case None = 'none';
}
