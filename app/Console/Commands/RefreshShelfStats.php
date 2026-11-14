<?php

namespace App\Console\Commands;

use App\Discovery\Recommendations\ShelfBandit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

#[Signature('bookflix:refresh-shelf-stats')]
#[Description('Count each Home shelf\'s impressions and clicks over the retention window into the cache the shelf order reads')]
class RefreshShelfStats extends Command
{
    /**
     * Impressions and clicks per shelf: one impression per recommendation row that has an impression event and one click per row that has a
     * click event, however often either repeated. Only rows still inside the retention window count, so the old ones that survive pruning
     * because they hold a dismissal do not skew it. A request never aggregates this; it only reads the cache.
     */
    public function handle(): int
    {
        $rows = DB::table('recommendations as r')
            ->where('r.generated_at', '>=', now()->subDays((int) config('bookflix.recommendations.retention_days')))
            ->groupBy('r.shelf')
            ->get([
                'r.shelf',
                DB::raw("count(*) filter (where exists (select 1 from recommendation_events e where e.recommendation_id = r.id and e.event = 'impression')) as impressions"),
                DB::raw("count(*) filter (where exists (select 1 from recommendation_events e where e.recommendation_id = r.id and e.event = 'click')) as clicks"),
            ]);

        $stats = [];

        foreach ($rows as $row) {
            $stats[$row->shelf] = [(int) $row->impressions, (int) $row->clicks];
        }

        // Longer than the hourly schedule, so a late run leaves the last stats in place; a lapse past that gives the default order.
        Cache::put(ShelfBandit::CACHE_KEY, $stats, now()->addHours(3));
        $this->info('Counted '.count($stats).' shelf(s).');

        return self::SUCCESS;
    }
}
