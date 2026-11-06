<?php

namespace App\Enums;

/**
 * Where a model version is in its life: a candidate becomes active only by passing the promotion gate, and at most one version per name is active.
 */
enum ModelStatus: string
{
    case Training = 'training';
    case Candidate = 'candidate';
    case Active = 'active';
    case Retired = 'retired';
}
