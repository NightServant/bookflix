<?php

namespace App\Enums;

enum AccessType: string
{
    case PublicDomain = 'public_domain';
    case Licensed = 'licensed';
    case UserOwned = 'user_owned';
    case ExternalLink = 'external_link';

    public function label(): string
    {
        return match ($this) {
            self::PublicDomain => __('Public domain'),
            self::Licensed => __('Licensed'),
            self::UserOwned => __('Your own file'),
            self::ExternalLink => __('External link'),
        };
    }
}
