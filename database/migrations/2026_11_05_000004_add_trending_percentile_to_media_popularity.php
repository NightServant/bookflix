<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds `trending_percentile` to `media_popularity`: the `cume_dist()` of `trending_score` (above 0 for any activity, 1 for the most active) among the media that had any interaction in
     * the last 30 days, and 0 for every other media. `Media::scopeTrending` adds a small multiple of it to `media.popularity_score`; ranking
     * only the media with activity keeps the many media with none from pulling the percentiles up. The readers filter is unchanged (and
     * `ml/tests/schema.sql` carries the same definition).
     */
    public function up(): void
    {
        DB::statement('drop materialized view if exists media_popularity');

        DB::statement(<<<'SQL'
            create materialized view media_popularity as
            with counts as (
                select
                    ui.media_id,
                    count(*) filter (where ui.occurred_at >= now() - interval '7 days') as interactions_7d,
                    count(*) as interactions_30d
                from user_interactions ui
                join users u on u.id = ui.user_id and u.deactivated_at is null
                left join profiles p on p.user_id = u.id
                where ui.media_id is not null
                    and ui.occurred_at >= now() - interval '30 days'
                    and coalesce(p.personalization_enabled, true)
                group by ui.media_id
            ),
            totals as (
                select
                    m.id as media_id,
                    coalesce(c.interactions_7d, 0)::integer as interactions_7d,
                    coalesce(c.interactions_30d, 0)::integer as interactions_30d,
                    coalesce(c.interactions_30d, 0) + m.ratings_count as engagement
                from media m
                left join counts c on c.media_id = m.id
                where m.deleted_at is null and m.merged_into_id is null
            ),
            scored as (
                select
                    media_id,
                    interactions_7d,
                    interactions_30d,
                    engagement,
                    (interactions_7d + 0.25 * (interactions_30d - interactions_7d))::double precision as trending_score
                from totals
            ),
            active as (
                select media_id, (cume_dist() over (order by trending_score))::double precision as trending_percentile
                from scored
                where interactions_30d > 0
            )
            select
                s.media_id,
                s.interactions_7d,
                s.interactions_30d,
                s.trending_score,
                (percent_rank() over (order by s.engagement))::double precision as popularity_percentile,
                coalesce(a.trending_percentile, 0)::double precision as trending_percentile
            from scored s
            left join active a on a.media_id = s.media_id
        SQL);

        DB::statement('create unique index media_popularity_media_id on media_popularity (media_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The view without the column is the previous migration's; recreate it from there.
        DB::statement('drop materialized view if exists media_popularity');

        DB::statement(<<<'SQL'
            create materialized view media_popularity as
            with counts as (
                select
                    ui.media_id,
                    count(*) filter (where ui.occurred_at >= now() - interval '7 days') as interactions_7d,
                    count(*) as interactions_30d
                from user_interactions ui
                join users u on u.id = ui.user_id and u.deactivated_at is null
                left join profiles p on p.user_id = u.id
                where ui.media_id is not null
                    and ui.occurred_at >= now() - interval '30 days'
                    and coalesce(p.personalization_enabled, true)
                group by ui.media_id
            ),
            totals as (
                select
                    m.id as media_id,
                    coalesce(c.interactions_7d, 0)::integer as interactions_7d,
                    coalesce(c.interactions_30d, 0)::integer as interactions_30d,
                    coalesce(c.interactions_30d, 0) + m.ratings_count as engagement
                from media m
                left join counts c on c.media_id = m.id
                where m.deleted_at is null and m.merged_into_id is null
            )
            select
                media_id,
                interactions_7d,
                interactions_30d,
                (interactions_7d + 0.25 * (interactions_30d - interactions_7d))::double precision as trending_score,
                (percent_rank() over (order by engagement))::double precision as popularity_percentile
            from totals
        SQL);

        DB::statement('create unique index media_popularity_media_id on media_popularity (media_id)');
    }
};
