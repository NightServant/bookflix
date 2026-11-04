<?php

namespace App\Discovery\Recommendations;

use App\Enums\ReasonType;
use App\Models\Media;

/**
 * Why a title was recommended, as the reader will read it: the reason type with the title or genre its sentence names.
 */
final readonly class Explanation
{
    public function __construct(
        public ReasonType $type,
        public ?string $reasonTitle = null,
        public ?string $genre = null,
    ) {}

    public static function fromCandidate(Candidate $candidate, ?Media $reasonMedia): self
    {
        $genre = $candidate->payload['genre'] ?? null;

        return new self($candidate->reason, $reasonMedia?->title, is_string($genre) ? $genre : null);
    }
}
