<?php

namespace App\Catalog\Popularity;

use App\Models\ExternalSource;
use Illuminate\Support\Facades\DB;

class PopularityScorer
{
    private const CHUNK = 5000;

    /**
     * Blend every media's metrics into `media.popularity_score` (0 to 1). Each (source, metric) pair ranks its media by ln(1+value)
     * as a percentile, so a source's scale never matters, and a media's score is the weighted mean of the ranks it has.
     * Ranking happens once in SQL into a temporary table; the media rows are then updated in id chunks with one UPDATE...FROM each.
     * The readers' own aggregate (the `bookflix` source) is left out. Media that lost all their metrics fall back to 0.
     *
     * @return int Media scored.
     */
    public function run(): int
    {
        $weights = (array) config('bookflix.popularity.weights', []);
        $case = 'case m.metric'.str_repeat(' when ? then ?::float8', count($weights)).' else 1::float8 end';
        $bindings = [];

        foreach ($weights as $metric => $weight) {
            array_push($bindings, (string) $metric, (float) $weight);
        }

        // The readers' own ratings (the synthetic `bookflix` source) carry no weight until the recommender can use them (Phase 6).
        $bindings[] = ExternalSource::READERS;

        DB::statement('drop table if exists popularity_scores');
        DB::statement(
            "create temporary table popularity_scores as
            select media_id, sum(weight * rank) / sum(weight) as score from (
                select m.media_id, {$case} as weight,
                    percent_rank() over (partition by m.external_source_id, m.metric order by ln(1 + greatest(m.value, 0))) as rank
                from media_metrics m
                where m.external_source_id not in (select id from external_sources where key = ?)
            ) ranked group by media_id",
            $bindings,
        );
        DB::statement('create unique index on popularity_scores (media_id)');

        $bounds = DB::selectOne('select min(media_id) as low, max(media_id) as high, count(*) as total from popularity_scores');
        $total = (int) $bounds->total;

        for ($low = (int) $bounds->low; $total > 0 && $low <= (int) $bounds->high; $low += self::CHUNK) {
            DB::update(
                'update media set popularity_score = s.score from popularity_scores s
                where s.media_id = media.id and s.media_id >= ? and s.media_id < ? and media.popularity_score is distinct from s.score',
                [$low, $low + self::CHUNK],
            );
        }

        DB::update('update media set popularity_score = 0 where popularity_score <> 0 and not exists (select 1 from popularity_scores s where s.media_id = media.id)');
        DB::statement('drop table popularity_scores');

        return $total;
    }
}
