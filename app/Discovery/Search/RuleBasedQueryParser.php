<?php

namespace App\Discovery\Search;

use App\Access\MatureAccess;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Reads a query against the reader's QueryVocabulary, left to right, longest phrase first: "dark fantasy manga" is the subgenre Dark Fantasy and
 * the type Manga, not the family Fantasy. A phrase right after a negation ("not romance", "without romance") is left out of the intent and of the
 * semantic text. Words in scripts without spacing (`進撃の巨人の漫画`) are also searched inside for a phrase. Nothing here throws: the query is
 * capped at the search box's length, invalid UTF-8 is scrubbed and a text with no words is an empty intent.
 *
 * The result is kept ten minutes per normalized query and access level, so once the vocabulary is cached a parse reads the reader's profile at most.
 */
class RuleBasedQueryParser implements QueryParser
{
    private const TTL = 600;

    /** Words that keep the next phrase (within two words) out of the intent. */
    private const NEGATIONS = ['not', 'no', 'without', 'except', 'excluding', 'never', 'nor', 'minus', 'sans', 'sin'];

    private const NEGATION_REACH = 2;

    /** Kinds that only narrow the results and say nothing about what a story is about, so they leave the semantic text. */
    private const FILTER_ONLY = ['type', 'length'];

    public function __construct(private QueryVocabulary $vocabulary) {}

    public function parse(string $query, ?User $viewer = null): QueryIntent
    {
        $words = QueryVocabulary::words(mb_substr(mb_scrub($query), 0, HybridSearch::MAX_QUERY_LENGTH));

        if ($words === []) {
            return new QueryIntent;
        }

        $key = 'query-intent:v1:'.MatureAccess::cacheKey($viewer).':'.sha1(implode(' ', $words));

        return new QueryIntent(...Cache::remember($key, self::TTL, fn (): array => $this->read($words, $this->vocabulary->lookup($viewer))->toArray()));
    }

    /**
     * @param  list<string>  $words
     * @param  array{phrases: array<string, array{0: string, 1: string}>, longest: int, unspaced: list<string>, slugs: array<string, list<string>>}  $vocabulary
     */
    private function read(array $words, array $vocabulary): QueryIntent
    {
        $keys = array_map(fn (string $word): string => QueryVocabulary::key([$word]), $words);
        $found = ['type' => [], 'genre' => [], 'theme' => [], 'dewey' => [], 'length' => []];
        $kept = [];
        $negation = null; // the index in $kept of the last negation word still in reach
        $reach = -1;

        for ($i = 0, $count = count($words); $i < $count;) {
            [$length, $kind, $value] = $this->phraseAt($keys, $i, $vocabulary) ?? [0, null, null];

            if ($length === 0) {
                if (in_array($words[$i], self::NEGATIONS, true)) {
                    $negation = count($kept);
                    $reach = $i + self::NEGATION_REACH;
                }

                foreach ($this->inside($keys[$i], $vocabulary) as [$innerKind, $innerValue]) {
                    $found[$innerKind][] = $innerValue;
                }

                $kept[] = $words[$i++];

                continue;
            }

            if ($i <= $reach && $negation !== null) {
                array_splice($kept, $negation); // "without romance" means nothing to a semantic leg that cannot read "without"
                $negation = null;
            } else {
                $found[$kind][] = $value;

                if (! in_array($kind, self::FILTER_ONLY, true)) {
                    array_push($kept, ...array_slice($words, $i, $length));
                }
            }

            $i += $length;
        }

        $unique = fn (array $values): array => array_values(array_unique($values));

        return new QueryIntent(
            mediaTypes: $unique($found['type']),
            genres: $unique($found['genre']),
            themes: $unique($found['theme']),
            deweyCode: $found['dewey'][0] ?? null,
            lengthHint: $found['length'][0] ?? null,
            semanticText: implode(' ', $kept),
        );
    }

    /**
     * The longest phrase starting at word $i, as [its length in words, kind, value], or null.
     *
     * @param  list<string>  $keys
     * @param  array{phrases: array<string, array{0: string, 1: string}>, longest: int, unspaced: list<string>, slugs: array<string, list<string>>}  $vocabulary
     * @return array{0: int, 1: string, 2: string}|null
     */
    private function phraseAt(array $keys, int $i, array $vocabulary): ?array
    {
        for ($length = min($vocabulary['longest'], count($keys) - $i); $length > 0; $length--) {
            $phrase = $vocabulary['phrases'][implode(' ', array_slice($keys, $i, $length))] ?? null;

            if ($phrase !== null) {
                return [$length, ...$phrase];
            }
        }

        return null;
    }

    /**
     * The unspaced-script phrases found inside one word that is not itself a phrase.
     *
     * @param  array{phrases: array<string, array{0: string, 1: string}>, longest: int, unspaced: list<string>, slugs: array<string, list<string>>}  $vocabulary
     * @return list<array{0: string, 1: string}>
     */
    private function inside(string $word, array $vocabulary): array
    {
        if (preg_match('/[^\x00-\x7F]/', $word) !== 1) {
            return [];
        }

        return array_values(array_map(
            fn (string $phrase): array => $vocabulary['phrases'][$phrase],
            array_filter($vocabulary['unspaced'], fn (string $phrase): bool => str_contains($word, $phrase)),
        ));
    }
}
