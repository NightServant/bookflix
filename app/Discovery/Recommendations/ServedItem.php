<?php

namespace App\Discovery\Recommendations;

use App\Enums\ReasonType;

/**
 * A title as it is put on a shelf: what the logger stores about it and the label the tile shows. `features` are the ranker's eight values
 * (empty when the title was not ranked); `payload` carries only what the reader may see (the genre of a "Matches your interest" label).
 */
final readonly class ServedItem
{
    /**
     * @param  array<string, float>  $features
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $shelf,
        public int $mediaId,
        public float $score,
        public ReasonType $reason,
        public ?int $reasonMediaId = null,
        public array $features = [],
        public array $payload = [],
        public ?string $label = null,
    ) {}
}
