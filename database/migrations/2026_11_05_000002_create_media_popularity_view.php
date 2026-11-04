<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Bookflix's own interaction counts per media, refreshed hourly with `concurrently` (hence the unique index). Only interactions of active
     * readers who allow personalization count, a missing profile row counting as allowed, so it also holds what a reader did before they opted out.
     * Every live media has a row (zeros when nothing happened): soft deleted and merged media have none. `popularity_percentile` ranks the
     * 30 day interactions plus the ratings count; how Trending blends this with `media.popularity_score` is decided where it is used.
     */
    public function up(): void
    {
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('drop materialized view if exists media_popularity');
    }
};
