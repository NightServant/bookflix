<?php

namespace App\Discovery\Search\Legs;

use App\Catalog\Ingestion\ProhibitedContent;
use App\Discovery\Search\SearchFilters;
use App\Discovery\Search\SearchLeg;
use App\Ml\MlClient;
use App\Ml\MlServiceUnavailable;
use App\Models\Media;
use App\Models\MediaEmbedding;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SemanticLeg implements SearchLeg
{
    /** The query embedding is the one ML call in a web request (R1), so it may cost the reader this long at most, in seconds. */
    public const TIMEOUT = 0.3;

    /** How long a query's embedding is reused, in hours. */
    public const CACHE_HOURS = 24;

    /** After a failure the leg is skipped this long, in seconds, so a dead service does not add the timeout to every search. */
    public const PAUSE_SECONDS = 30;

    /** The most ids the leg lists: it only adds what the keyword legs miss, so it never makes the total look open-ended (`hasMore`). */
    public const MAX_IDS = 30;

    /** How many nearest rows of the active version are fetched before the filters narrow them; `ef_search` must be at least this. */
    private const NEIGHBOURS = 200;

    private const EF_SEARCH = 400;

    private const PAUSE_KEY = 'q-embed:paused';

    /**
     * The nearest neighbours of the last text, so a search that relaxes its intent (and runs the legs again) asks the service and the index once.
     *
     * @var array{key: string, ids: list<int>}|null
     */
    private ?array $last = null;

    public function __construct(private MlClient $ml) {}

    public function key(): string
    {
        return 'semantic';
    }

    /**
     * Media whose embedding under the active `embedding` version is close to the text's, nearest first. The nearest rows are fetched before the
     * filters are applied, so the HNSW index serves the query on any pgvector version; the filters (and with them the access gate, merges and
     * soft deletes) then narrow those rows as they narrow the keyword leg. Without an active version, for text under three characters or
     * without a letter or digit, for text naming prohibited content, while the service is paused, or when its answer comes from another model, the leg finds nothing and search is
     * keyword-only.
     */
    public function ids(string $query, SearchFilters $filters, int $limit = 100): array
    {
        $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query) ?? ''));

        if (mb_strlen($text) < 3 || ! preg_match('/[\p{L}\p{N}]/u', $text) || ProhibitedContent::matches($text)) {
            return [];
        }

        $version = ModelVersion::activeFor('embedding');

        if ($version === null) {
            return [];
        }

        $key = "{$version->id}:".sha1($text);

        if ($this->last === null || $this->last['key'] !== $key) {
            $vector = $this->embedding($text, $version, $key);
            $this->last = ['key' => $key, 'ids' => $vector === null ? [] : $this->neighbours($vector, $version)];
        }

        if ($this->last['ids'] === []) {
            return [];
        }

        $visible = $filters->apply(Media::query()->unmerged())->whereIn('media.id', $this->last['ids'])->pluck('media.id')->all();

        return array_slice(array_values(array_intersect($this->last['ids'], $visible)), 0, min($limit, self::MAX_IDS));
    }

    /**
     * The text's query embedding from the cache, or from the service. Any failure, or an answer from a model other than the active version's
     * (two models' vectors are never compared), logs one warning and pauses the leg.
     *
     * @return list<float>|null
     */
    private function embedding(string $text, ModelVersion $version, string $key): ?array
    {
        /** @var list<float>|null $cached */
        $cached = Cache::get("q-embed:{$key}");

        if ($cached !== null) {
            return $cached;
        }

        if (Cache::has(self::PAUSE_KEY)) {
            return null;
        }

        $model = $version->parameters['model'] ?? null;
        $revision = $version->parameters['revision'] ?? null;
        $dims = $version->parameters['dims'] ?? null;

        try {
            $result = $this->ml->embed([$text], 'query', self::TIMEOUT);

            if ($result->modelVersion !== "{$model}@{$revision}" || $result->dims !== $dims) {
                throw new MlServiceUnavailable("The ML service embeds with {$result->modelVersion} ({$result->dims} dims), not the active version {$version->id}.");
            }
        } catch (MlServiceUnavailable $exception) {
            Cache::put(self::PAUSE_KEY, true, now()->addSeconds(self::PAUSE_SECONDS));
            Log::warning('Semantic search is keyword-only: '.$exception->getMessage(), ['model_version_id' => $version->id]);

            return null;
        }

        Cache::put("q-embed:{$key}", $result->vectors[0], now()->addHours(self::CACHE_HOURS));

        return $result->vectors[0];
    }

    /**
     * The active version's rows nearest the vector and at least `semantic_min_similarity` close, nearest first. `ef_search` is raised for this
     * statement only, so the approximate index scan has enough candidates left after the other versions' rows are filtered out.
     *
     * @param  list<float>  $vector
     * @return list<int>
     */
    private function neighbours(array $vector, ModelVersion $version): array
    {
        return DB::transaction(function () use ($vector, $version): array {
            DB::statement('set local hnsw.ef_search = '.self::EF_SEARCH);

            return array_values(MediaEmbedding::query()
                ->where('model_version_id', $version->id)
                ->whereVectorSimilarTo('embedding', $vector, (float) config('bookflix.search.semantic_min_similarity'))
                ->limit(self::NEIGHBOURS)
                ->pluck('media_id')
                ->all());
        });
    }
}
