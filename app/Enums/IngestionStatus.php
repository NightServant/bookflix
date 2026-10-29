<?php

namespace App\Enums;

enum IngestionStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
}
