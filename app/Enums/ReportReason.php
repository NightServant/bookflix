<?php

namespace App\Enums;

enum ReportReason: string
{
    case Spam = 'spam';
    case Abuse = 'abuse';
    case Spoiler = 'spoiler';
    case Copyright = 'copyright';
    case Other = 'other';

    /**
     * How the report dialog and Admin, Reports name the reason.
     */
    public function label(): string
    {
        return (string) __(match ($this) {
            self::Spam => 'Spam or advertising',
            self::Abuse => 'Abusive or hateful',
            self::Spoiler => 'Spoilers not marked',
            self::Copyright => 'Copyright problem',
            self::Other => 'Something else',
        });
    }
}
