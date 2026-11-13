<?php

namespace App\Ml;

/**
 * Vectors from `/v1/embed`, one per text in the order sent. `modelVersion` is `"{model}@{revision}"`, which a caller compares with the active
 * `embedding` version before using the vectors, so two models' vectors are never compared.
 */
final readonly class EmbeddingResult
{
    /**
     * @param  list<list<float>>  $vectors
     */
    public function __construct(
        public string $modelVersion,
        public int $dims,
        public array $vectors,
    ) {}
}
