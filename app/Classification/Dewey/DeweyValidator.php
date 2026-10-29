<?php

namespace App\Classification\Dewey;

use App\Classification\ClassificationCandidate;
use App\Enums\ClassificationMethod;

class DeweyValidator
{
    /**
     * Drop weak and repeated candidates and cap the rest: the strongest is the primary (index 0), then at most `max_secondary` more.
     * Ties go to manual, then external, then automatic. A code seen twice keeps its highest confidence.
     *
     * @param  list<ClassificationCandidate>  $candidates
     * @return list<ClassificationCandidate>
     */
    public function filter(array $candidates): array
    {
        $min = config('bookflix.classification.min_confidence');
        $limit = 1 + config('bookflix.classification.max_secondary');

        $kept = collect($candidates)
            ->filter(fn (ClassificationCandidate $candidate) => $candidate->confidence >= $min)
            ->sort(fn (ClassificationCandidate $a, ClassificationCandidate $b) => [$b->confidence, $this->rank($b->method)] <=> [$a->confidence, $this->rank($a->method)])
            ->unique('code')
            ->take($limit);

        return array_values($kept->all());
    }

    protected function rank(ClassificationMethod $method): int
    {
        return match ($method) {
            ClassificationMethod::Manual => 3,
            ClassificationMethod::External => 2,
            ClassificationMethod::Automatic => 1,
        };
    }
}
