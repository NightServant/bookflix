<?php

namespace App\Enums;

enum IngestionMode: string
{
    case Full = 'full';
    case Incremental = 'incremental';
    case Single = 'single';
}
