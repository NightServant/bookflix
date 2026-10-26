<?php

namespace App\Classification;

use App\Enums\ClassificationMethod;

final readonly class ClassificationCandidate
{
    public function __construct(
        public string $code,
        public float $confidence,
        public ClassificationMethod $method,
        public string $source,
        public ?int $modelVersionId = null,
    ) {}
}
