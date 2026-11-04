<?php

namespace App\Discovery\Recommendations;

use App\Models\ModelVersion;

/**
 * How much each feature counts in the hybrid score. The active `hybrid-weights` version holds them, so they can change without a deploy;
 * with no active version the config values apply. A weight that is missing, negative or not a finite number counts as zero, so a bad row can
 * make a feature count for nothing but cannot make a score NaN.
 */
final readonly class RankingWeights
{
    public function __construct(
        public float $content,
        public float $collaborative,
        public float $dewey,
        public float $popularity,
        public float $freshness,
        public float $preference,
        public float $novelty,
        public float $context = 0.0,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $weight = function (string $key) use ($values): float {
            $value = $values[$key] ?? 0.0;

            return is_numeric($value) && is_finite((float) $value) && $value >= 0 ? (float) $value : 0.0;
        };

        return new self(
            $weight('content'), $weight('collaborative'), $weight('dewey'), $weight('popularity'),
            $weight('freshness'), $weight('preference'), $weight('novelty'), $weight('context'),
        );
    }

    public static function active(): self
    {
        return self::fromArray(ModelVersion::activeFor('hybrid-weights')?->parameters ?? config('bookflix.hybrid_weights'));
    }

    /**
     * @return array<string, float>
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'collaborative' => $this->collaborative,
            'dewey' => $this->dewey,
            'popularity' => $this->popularity,
            'freshness' => $this->freshness,
            'preference' => $this->preference,
            'novelty' => $this->novelty,
            'context' => $this->context,
        ];
    }
}
