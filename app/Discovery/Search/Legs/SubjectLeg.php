<?php

namespace App\Discovery\Search\Legs;

use App\Discovery\Search\SearchFilters;
use App\Discovery\Search\SearchLeg;
use App\Models\DeweyClass;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use Illuminate\Database\Query\JoinClause;

class SubjectLeg implements SearchLeg
{
    public function key(): string
    {
        return 'subject';
    }

    /**
     * Media whose public primary class is, or sits below, a class whose name holds every query word (accents ignored) or whose code is the query.
     * Only the primary class counts, so a secondary or rejected class never makes a story turn up.
     */
    public function ids(string $query, SearchFilters $filters, int $limit = 100): array
    {
        $classIds = DeweyClass::query()
            ->from('dewey_classes as klass')
            ->join('dewey_classes as target', fn (JoinClause $join) => $join->whereRaw("klass.path = target.path or klass.path like target.path || '/%'"))
            ->where(fn ($match) => $match
                ->whereRaw("to_tsvector('simple', immutable_unaccent(target.name)) @@ plainto_tsquery('simple', immutable_unaccent(?))", [$query])
                ->orWhere('target.code', $query))
            ->select('klass.id');

        $ids = $filters->apply(Media::query()->unmerged())
            ->whereIn('media.id', MediaDeweyClass::query()->publicPrimary()->whereIn('dewey_class_id', $classIds)->select('media_id'))
            ->orderByDesc('media.ratings_count')
            ->orderBy('media.id')
            ->limit($limit)
            ->pluck('media.id');

        return array_values($ids->all());
    }
}
