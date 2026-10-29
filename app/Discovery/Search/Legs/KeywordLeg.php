<?php

namespace App\Discovery\Search\Legs;

use App\Discovery\Search\Rrf;
use App\Discovery\Search\SearchFilters;
use App\Discovery\Search\SearchLeg;
use App\Models\Media;
use Illuminate\Database\Eloquent\Builder;

class KeywordLeg implements SearchLeg
{
    /**
     * The same expressions the trigram indexes are built on; a different spelling means Postgres ignores the index.
     */
    private const TITLE = 'immutable_unaccent(lower(media.title))';

    private const ALTERNATIVE_TITLES = 'immutable_unaccent(lower(cast(media.alternative_titles as text)))';

    public function key(): string
    {
        return 'keyword';
    }

    /**
     * Full-text hits (Scout, whole terms), full-text hits with the last term as a prefix (so `japan` finds `Japanese` while the reader is
     * still typing), typo-tolerant trigram hits on the title and the alternative titles, and a substring fallback for non-ASCII
     * queries, whose scripts have no word boundaries. The lists are fused so a title that several of them agree on wins.
     */
    public function ids(string $query, SearchFilters $filters, int $limit = 100): array
    {
        $lists = [
            array_values(Media::search($query)->query(fn (Builder $media) => $this->visible($media->select('media.id'), $filters))->take($limit)->keys()->all()),
            $this->prefixed($query, $filters, $limit),
            $this->similar(self::TITLE, $query, $filters, $limit),
            $this->similar(self::ALTERNATIVE_TITLES, $query, $filters, $limit),
        ];

        if (preg_match('/[^\x00-\x7F]/', $query)) {
            $like = '%'.addcslashes($query, '\\%_').'%';

            $lists[] = array_values($this->visible(Media::query(), $filters)
                ->where(fn (Builder $media) => $media
                    ->whereRaw('media.title ilike ?', [$like])
                    ->orWhereRaw('cast(media.alternative_titles as text) ilike ?', [$like]))
                ->orderBy('media.id')
                ->limit($limit)
                ->pluck('media.id')
                ->all());
        }

        return array_slice(array_keys(Rrf::fuse($lists)), 0, $limit);
    }

    /**
     * Media whose search text holds every word of the query, the last one as a prefix (none for a query with punctuation in it). Runs on the same `to_tsvector('simple', search_text)` index as Scout.
     *
     * @return list<int>
     */
    private function prefixed(string $query, SearchFilters $filters, int $limit): array
    {
        if (preg_match('/[^\p{L}\p{M}\p{N}\s]/u', $query)) {
            return []; // punctuation is literal, as in the substring fallback: it never turns into a wildcard
        }

        $tsquery = collect(explode(' ', $query))->map(fn (string $word): string => "'{$word}'")->implode(' & ').':*';

        return array_values($this->visible(Media::query(), $filters)
            ->whereRaw("to_tsvector('simple', media.search_text) @@ to_tsquery('simple', ?)", [$tsquery])
            ->orderByRaw("ts_rank(to_tsvector('simple', media.search_text), to_tsquery('simple', ?)) desc", [$tsquery])
            ->orderBy('media.id') // equal ranks come back in a stable order, whatever plan Postgres picks
            ->limit($limit)
            ->pluck('media.id')
            ->all());
    }

    /**
     * Media whose unaccented, lowercased expression is trigram-similar to the query (pg_trgm's default 0.3 threshold), most similar first.
     *
     * @param  literal-string  $expression
     * @return list<int>
     */
    private function similar(string $expression, string $query, SearchFilters $filters, int $limit): array
    {
        return array_values($this->visible(Media::query(), $filters)
            ->whereRaw("{$expression} % immutable_unaccent(lower(?))", [$query])
            ->orderByRaw("similarity({$expression}, immutable_unaccent(lower(?))) desc", [$query])
            ->orderBy('media.id')
            ->limit($limit)
            ->pluck('media.id')
            ->all());
    }

    /**
     * The live catalogue the filters narrow.
     *
     * @param  Builder<Media>  $media
     * @return Builder<Media>
     */
    private function visible(Builder $media, SearchFilters $filters): Builder
    {
        return $filters->apply($media->unmerged());
    }
}
