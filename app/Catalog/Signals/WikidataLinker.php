<?php

namespace App\Catalog\Signals;

use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Models\ExternalSource;
use App\Models\Media;
use App\Models\MediaExternalId;
use Illuminate\Support\Facades\DB;

/**
 * Finds the Wikidata item (and English Wikipedia article) of media that has none yet, one SPARQL query per batch.
 */
class WikidataLinker
{
    use SendsSignalRequests;

    private const ENDPOINT = 'https://query.wikidata.org/sparql';

    /**
     * Our source key => the Wikidata property holding that source's id.
     *
     * @var array<string, string>
     */
    private const PROPERTIES = [
        'anilist' => 'P8731',
        'myanimelist' => 'P4087',
        'mangadex' => 'P10589',
        'mangaupdates' => 'P11149',
        'openlibrary' => 'P648',
    ];

    /**
     * Check the media least recently asked about and link what Wikidata knows.
     *
     * @return array{checked: int, linked: int}
     *
     * @throws RateLimitedByProvider
     * @throws ProviderUnavailable
     */
    public function run(int $batch): array
    {
        $source = ExternalSource::where('key', 'wikidata')->firstOrFail();

        $media = Media::query()->unmerged()
            ->select('media.*')
            ->leftJoin('media_signal_checks as checks', fn ($join) => $join->on('checks.media_id', '=', 'media.id')->where('checks.external_source_id', $source->id))
            ->whereDoesntHave('externalIds', fn ($query) => $query->where('external_source_id', $source->id))
            ->whereHas('externalIds.externalSource', fn ($query) => $query->whereIn('key', array_keys(self::PROPERTIES)))
            ->orderByRaw('checks.checked_at asc nulls first')
            ->orderByDesc('media.popularity_score')
            ->orderBy('media.id')
            ->limit($batch)
            ->with('externalIds.externalSource')
            ->get();

        if ($media->isEmpty()) {
            return ['checked' => 0, 'linked' => 0];
        }

        $lookups = [];

        foreach ($media as $row) {
            foreach ($row->externalIds as $externalId) {
                $property = self::PROPERTIES[$externalId->externalSource->key] ?? null;
                $value = $externalId->externalSource->key === 'mangaupdates' && ctype_digit($externalId->external_id)
                    ? base_convert($externalId->external_id, 10, 36)
                    : $externalId->external_id;

                if ($property !== null && preg_match('/^[A-Za-z0-9]+$/', $value)) {
                    $lookups["{$property}|{$value}"][] = $row->id;
                }
            }
        }

        $found = $this->query(array_keys($lookups));
        $linked = 0;

        foreach ($media as $row) {
            foreach ($found as $key => $item) {
                if (in_array($row->id, $lookups[$key] ?? [], true) && $this->link($row, $source, $item)) {
                    $linked++;

                    break;
                }
            }
        }

        DB::table('media_signal_checks')->upsert(
            $media->map(fn (Media $row): array => ['media_id' => $row->id, 'external_source_id' => $source->id, 'checked_at' => now()])->all(),
            ['media_id', 'external_source_id'],
            ['checked_at'],
        );

        return ['checked' => $media->count(), 'linked' => $linked];
    }

    /**
     * @param  list<string>  $keys  `P8731|30002` pairs
     * @return array<string, array{qid: string, article: ?string}> by pair
     */
    private function query(array $keys): array
    {
        $values = implode(' ', array_map(function (string $key): string {
            [$property, $value] = explode('|', $key, 2);

            return "(wdt:{$property} \"{$value}\")";
        }, $keys));

        $sparql = "SELECT ?item ?p ?value ?article WHERE { VALUES (?p ?value) { {$values} } ?item ?p ?value . "
            .'OPTIONAL { ?article schema:about ?item ; schema:isPartOf <https://en.wikipedia.org/> . } }';

        $bindings = $this->throttled('wikidata', fn () => $this->client()->timeout(60)->asForm()->post(self::ENDPOINT, ['query' => $sparql, 'format' => 'json']))
            ->json('results.bindings');
        $found = [];

        foreach (is_array($bindings) ? $bindings : [] as $binding) {
            $qid = basename((string) ($binding['item']['value'] ?? ''));
            $property = basename((string) ($binding['p']['value'] ?? ''));
            $value = (string) ($binding['value']['value'] ?? '');

            if (! preg_match('/^Q\d+$/', $qid) || $property === '' || $value === '') {
                continue;
            }

            $article = (string) ($binding['article']['value'] ?? '');
            $found["{$property}|{$value}"] ??= ['qid' => $qid, 'article' => str_starts_with($article, 'https://en.wikipedia.org/wiki/') ? $article : null];
        }

        return $found;
    }

    /**
     * @param  array{qid: string, article: ?string}  $item
     */
    private function link(Media $media, ExternalSource $source, array $item): bool
    {
        if (MediaExternalId::where('external_source_id', $source->id)->where('external_id', $item['qid'])->exists()) {
            return false;
        }

        MediaExternalId::create([
            'media_id' => $media->id,
            'external_source_id' => $source->id,
            'external_id' => $item['qid'],
            'source_url' => $item['article'],
            'payload_hash' => hash('sha256', $item['qid'].'|'.$item['article']),
            'imported_at' => now(),
            'last_synced_at' => now(),
        ]);

        return true;
    }
}
