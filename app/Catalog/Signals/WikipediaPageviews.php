<?php

namespace App\Catalog\Signals;

use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Models\ExternalSource;
use Illuminate\Support\Facades\DB;

/**
 * Records the English Wikipedia views of the last 30 full days (`pageviews_30d`) for media whose Wikidata link carries an article.
 */
class WikipediaPageviews
{
    use SendsSignalRequests;

    private const ENDPOINT = 'https://wikimedia.org/api/rest_v1/metrics/pageviews/per-article/en.wikipedia/all-access/user';

    /**
     * Check the linked media least recently asked about, bounded by the batch size.
     *
     * @return array{checked: int, recorded: int}
     *
     * @throws RateLimitedByProvider
     * @throws ProviderUnavailable
     */
    public function run(int $batch): array
    {
        $wikipedia = ExternalSource::where('key', 'wikipedia')->firstOrFail();
        $wikidata = ExternalSource::where('key', 'wikidata')->firstOrFail();

        $rows = DB::table('media')
            ->join('media_external_ids as links', fn ($join) => $join->on('links.media_id', '=', 'media.id')->where('links.external_source_id', $wikidata->id)->whereNotNull('links.source_url'))
            ->leftJoin('media_signal_checks as checks', fn ($join) => $join->on('checks.media_id', '=', 'media.id')->where('checks.external_source_id', $wikipedia->id))
            ->whereNull('media.merged_into_id')
            ->whereNull('media.deleted_at')
            ->orderByRaw('checks.checked_at asc nulls first')
            ->orderByDesc('media.popularity_score')
            ->orderBy('media.id')
            ->limit($batch)
            ->get(['media.id', 'links.source_url']);

        $end = now()->subDay();
        $range = $end->copy()->subDays(29)->format('Ymd').'00/'.$end->format('Ymd').'00';
        $recorded = 0;

        foreach ($rows as $row) {
            $title = rawurlencode(rawurldecode(substr((string) $row->source_url, strlen('https://en.wikipedia.org/wiki/'))));
            $response = $this->throttled('wikipedia', fn () => $this->client()->get(self::ENDPOINT."/{$title}/daily/{$range}"));
            $items = $response->json('items');

            if (is_array($items)) {
                $views = (int) collect($items)->sum(fn ($item): int => is_array($item) && is_int($item['views'] ?? null) ? $item['views'] : 0);

                DB::table('media_metrics')->upsert(
                    [['media_id' => $row->id, 'external_source_id' => $wikipedia->id, 'metric' => 'pageviews_30d', 'value' => $views, 'observed_at' => now()]],
                    ['media_id', 'external_source_id', 'metric'],
                    ['value', 'observed_at'],
                );
                $recorded++;
            }

            DB::table('media_signal_checks')->upsert(
                [['media_id' => $row->id, 'external_source_id' => $wikipedia->id, 'checked_at' => now()]],
                ['media_id', 'external_source_id'],
                ['checked_at'],
            );
        }

        return ['checked' => $rows->count(), 'recorded' => $recorded];
    }
}
