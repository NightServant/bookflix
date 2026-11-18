<?php

namespace App\Enums;

enum ReportReason: string
{
    case Spam = 'spam';
    case Abuse = 'abuse';
    case Spoiler = 'spoiler';
    case Copyright = 'copyright';
    case Other = 'other';
}
