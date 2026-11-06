<?php

namespace App\Discovery\Recommendations;

use App\Enums\ReasonType;

/**
 * A title one source proposes, with the score in [0, 1] and why. `reasonMediaId` is the library title a "Because you read ..." label names;
 * `payload` carries what other labels need (`['genre' => 'Fantasy']`).
 */
final readonly class Candidate
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $mediaId,
        public string $source,
        public float $score,
        public ReasonType $reason,
        public ?int $reasonMediaId = null,
        public array $payload = [],
    ) {}
}
