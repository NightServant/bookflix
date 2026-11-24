<?php

namespace App\Discovery\Search;

use App\Access\MatureAccess;
use App\Ai\LlmUnavailable;
use App\Catalog\Ingestion\ProhibitedContent;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The model reads the query when it may (AI on, a signed-in reader, nothing prohibited, under the hourly limit); the rules read it otherwise,
 * and whenever the model is unavailable. The model's answer is kept a day per normalized query, vocabulary build and access level.
 */
class FallbackQueryParser implements QueryParser
{
    private const TTL = 86400;

    public function __construct(
        private LlmQueryParser $llm,
        private RuleBasedQueryParser $rules,
        private QueryVocabulary $vocabulary,
    ) {}

    public function parse(string $query, ?User $viewer = null): QueryIntent
    {
        try {
            if ($viewer !== null && config('bookflix.ai.enabled') && ! ProhibitedContent::matches($query)) {
                $words = QueryVocabulary::words(mb_substr(mb_scrub($query), 0, HybridSearch::MAX_QUERY_LENGTH));

                if ($words !== []) {
                    return $this->viaModel($query, $words, $viewer);
                }
            }
        } catch (LlmUnavailable) {
            // The rules answer below.
        } catch (Throwable $e) {
            report($e);
        }

        return $this->rules->parse($query, $viewer);
    }

    /**
     * @param  list<string>  $words
     *
     * @throws LlmUnavailable
     */
    private function viaModel(string $query, array $words, User $viewer): QueryIntent
    {
        $key = 'query-intent:llm:v1:'.$this->vocabulary->lookup($viewer)['generation'].':'.MatureAccess::cacheKey($viewer).':'.sha1(implode(' ', $words));
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return QueryIntent::fromArray($cached);
        }

        $limit = RateLimiter::limiter('ai-intent')($viewer);

        if (! RateLimiter::attempt('ai-intent:'.$viewer->id, $limit->maxAttempts, fn (): bool => true, $limit->decaySeconds)) {
            throw new LlmUnavailable('Over the hourly intent limit.');
        }

        $intent = $this->llm->parse($query, $viewer);
        Cache::put($key, $intent->toArray(), self::TTL);

        return $intent;
    }
}
