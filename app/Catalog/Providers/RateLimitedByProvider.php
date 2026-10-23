<?php

namespace App\Catalog\Providers;

use RuntimeException;

class RateLimitedByProvider extends RuntimeException
{
    public function __construct(public readonly int $retryAfter, string $message = 'The provider rate-limited the request.')
    {
        parent::__construct($message);
    }
}
