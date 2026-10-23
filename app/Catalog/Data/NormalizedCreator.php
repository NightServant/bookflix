<?php

namespace App\Catalog\Data;

use App\Enums\CreatorRole;

final readonly class NormalizedCreator
{
    public function __construct(
        public string $name,
        public CreatorRole $role,
    ) {}
}
