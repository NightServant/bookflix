<?php

namespace App\Discovery\Search;

use App\Catalog\Ingestion\ProhibitedContent;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Reads a query against the reader's QueryVocabulary, left to right, longest phrase first: "dark fantasy manga" is the subgenre Dark Fantasy and
 * the type Manga, not the family Fantasy. A negation ("not romance", "without magic") is left out of the intent and of the semantic text with
 * what it negates. Words in scripts without spacing (`進撃の巨人の漫画`) are also searched inside for a phrase. Nothing here throws: the query is
 * capped at the search box's length, invalid UTF-8 is scrubbed and a text with no words is an empty intent.
 *
 * The semantic text is the reader's words minus the negated ones: type and length words stay ("The Long Walk", "Manga Guide to Databases"),
 * and a query naming prohibited content has none, so the term is neither embedded nor stored a second time in `search_queries.intent`.
 *
 * The result is kept ten minutes per normalized query and vocabulary build (one per access level), so once the vocabulary is cached a parse
 * reads the reader's profile at most, and a re-seed, which drops the vocabulary, never serves an old slug.
 */
class RuleBasedQueryParser implements QueryParser
{
    private const TTL = 600;

    /** Words that keep what follows them out of the intent and the semantic text: the next phrase, or the next word and a phrase right after it. */
    private const NEGATIONS = ['not', 'no', 'without', 'except', 'excluding', 'never', 'nor', 'minus', 'sans', 'sin'];

    public function __construct(private QueryVocabulary $vocabulary) {}

    public function parse(string $query, ?User $viewer = null): QueryIntent
    {
        $words = QueryVocabulary::words(mb_substr(mb_scrub($query), 0, HybridSearch::MAX_QUERY_LENGTH));

        if ($words === []) {
            return new QueryIntent;
        }

        $vocabulary = $this->vocabulary->lookup($viewer);
        $key = 'query-intent:v2:'.$vocabulary['generation'].':'.sha1(implode(' ', $words));

        return QueryIntent::fromArray(Cache::remember($key, self::TTL, fn (): array => $this->read($words, $vocabulary)->toArray()));
    }

    /**
     * @param  list<string>  $words
     * @param  array{phrases: array<string, array{0: string, 1: string}>, longest: int, unspaced: list<string>, slugs: array<string, list<string>>, generation: string}  $vocabulary
     */
    private function read(array $words, array $vocabulary): QueryIntent
    {
        $keys = array_map(fn (string $word): string => QueryVocabulary::key([$word]), $words);
        $spans = []; // [first word, length, kind or null, value or null]

        for ($i = 0, $count = count($words); $i < $count; $i += $spans[array_key_last($spans)][1]) {
            [$length, $kind, $value] = $this->phraseAt($keys, $i, $vocabulary) ?? [1, null, null];
            $spans[] = [$i, $length, $kind, $value];
        }

        $found = ['type' => [], 'genre' => [], 'theme' => [], 'dewey' => [], 'length' => []];
        $kept = [];

        for ($s = 0, $count = count($spans); $s < $count; $s++) {
            [$first, $length, $kind, $value] = $spans[$s];

            if ($kind === null && in_array($words[$first], self::NEGATIONS, true)) {
                $s = $this->negatedThrough($spans, $words, $s);

                continue;
            }

            if ($kind === null) {
                foreach ($this->inside($keys[$first], $vocabulary) as [$innerKind, $innerValue]) {
                    $found[$innerKind][] = $innerValue;
                }
            } else {
                $found[$kind][] = $value;
            }

            array_push($kept, ...array_slice($words, $first, $length));
        }

        $unique = fn (array $values): array => array_values(array_unique($values));
        $text = implode(' ', $kept);

        return new QueryIntent(
            mediaTypes: $unique($found['type']),
            genres: $unique($found['genre']),
            themes: $unique($found['theme']),
            deweyCode: $found['dewey'][0] ?? null,
            lengthHint: $found['length'][0] ?? null,
            semanticText: ProhibitedContent::matches(implode(' ', $words)) ? '' : $text,
        );
    }

    /**
     * The last span a negation at span $s reaches: the next phrase; or the next word, and a phrase right after it ("without any romance");
     * a negation it reaches negates in turn ("not not romance" leaves nothing).
     *
     * @param  list<array{0: int, 1: int, 2: string|null, 3: string|null}>  $spans
     * @param  list<string>  $words
     */
    private function negatedThrough(array $spans, array $words, int $s): int
    {
        $next = $s + 1;

        if (! isset($spans[$next]) || $spans[$next][2] !== null) {
            return min($next, count($spans) - 1);
        }

        if (in_array($words[$spans[$next][0]], self::NEGATIONS, true)) {
            return $this->negatedThrough($spans, $words, $next);
        }

        return isset($spans[$next + 1]) && $spans[$next + 1][2] !== null ? $next + 1 : $next;
    }

    /**
     * The longest phrase starting at word $i, as [its length in words, kind, value], or null.
     *
     * @param  list<string>  $keys
     * @param  array{phrases: array<string, array{0: string, 1: string}>, longest: int, unspaced: list<string>, slugs: array<string, list<string>>, generation: string}  $vocabulary
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
     * @param  array{phrases: array<string, array{0: string, 1: string}>, longest: int, unspaced: list<string>, slugs: array<string, list<string>>, generation: string}  $vocabulary
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
