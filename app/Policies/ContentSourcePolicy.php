<?php

namespace App\Policies;

use App\Models\ContentSource;
use App\Models\User;
use App\Reader\ContentAccessGate;

/**
 * Who may read a content source: exactly what ContentAccessGate says, so a Gate check and a direct call cannot disagree.
 */
class ContentSourcePolicy
{
    public function __construct(private readonly ContentAccessGate $gate) {}

    public function view(User $user, ContentSource $source): bool
    {
        return $this->gate->allows($user, $source);
    }
}
