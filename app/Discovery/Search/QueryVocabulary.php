<?php

namespace App\Discovery\Search;

use App\Access\MatureAccess;
use App\Catalog\Ingestion\ProhibitedContent;
use App\Models\DeweyClass;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Transliterator;

/**
 * The phrases a search query can name the taxonomy with, each owned by one value: a media type, genre, theme, Dewey class or length bucket.
 * Built from the live taxonomy (TaxonomyFacets, the Dewey classes) and the curated files, kept ten minutes per access level, so a reader without
 * mature access is never offered a mature genre (MatureAccess::cacheKey).
 *
 * When two sources name the same phrase the first one in this order wins: `query_synonyms.csv` (written for queries), media type names,
 * genre names, theme names, Dewey subject keywords, the provider term maps and retired slugs under `taxonomy/`, then Dewey class names.
 * A phrase that is a prohibited term (ProhibitedContent) is never in the vocabulary, and a value the reader cannot see never is either.
 */
class QueryVocabulary
{
    private const TTL = 600;

    /** Listed in TaxonomyFacets::KEYS with each access level, so seeding drops it. */
    private const CACHE_KEY = 'query-vocabulary:v1:';

    /** The provider maps under `database/data/taxonomy/` (provider_term,kind,bookflix_slug) and the vocabulary kind each of their kinds names. */
    private const PROVIDER_FILES = ['hardcover', 'openlibrary', 'anilist', 'mangadex', 'mangaupdates', 'metron'];

    private const PROVIDER_KINDS = ['genre' => 'genre', 'subgenre' => 'genre', 'theme' => 'theme'];

    /** Scripts written without spaces between words: their phrases are also found inside a longer run of text. */
    private const UNSPACED = '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u';

    private static ?Transliterator $folder = null;

    public function __construct(private TaxonomyFacets $facets) {}

    /**
     * The phrase table this reader parses with: `phrases` maps a phrase key (see key()) to its kind and value, `longest` is the most words
     * a phrase has, and `unspaced` lists the phrases in scripts without word spacing.
     *
     * @return array{phrases: array<string, array{0: string, 1: string}>, longest: int, unspaced: list<string>, slugs: array<string, list<string>>}
     */
    public function lookup(?User $viewer): array
    {
        $access = MatureAccess::cacheKey($viewer);

        return Cache::remember(self::CACHE_KEY.$access, self::TTL, fn (): array => $this->build($access === 'mature'));
    }

    /**
     * The values this reader may be offered, by kind (type, genre, theme, dewey, length): anything else in an intent is dropped.
     *
     * @return array<string, list<string>>
     */
    public function slugs(?User $viewer): array
    {
        return $this->lookup($viewer)['slugs'];
    }

    /**
     * The words of a text: accents folded off Latin letters, lowercased, split on anything that is not a letter, mark or digit (so `SCI-FI` is
     * `sci fi` and emoji vanish). Invalid UTF-8 is scrubbed first.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $text = mb_scrub($text);
        self::$folder ??= Transliterator::create('NFC; [:Latin:] Latin-ASCII');
        $folded = self::$folder?->transliterate($text) ?: $text;

        return preg_split('/[^\p{L}\p{M}\p{N}]+/u', Str::lower($folded), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * The lookup key of a run of words: each Latin word of four letters or more singularized, so `mangas` and `graphic novels` find their
     * phrases. Phrases and queries go through the same function, so an odd singular on both sides still matches.
     *
     * @param  list<string>  $words
     */
    public static function key(array $words): string
    {
        return implode(' ', array_map(fn (string $word): string => preg_match('/^[a-z]{4,}$/', $word) ? Str::singular($word) : $word, $words));
    }

    /**
     * @return array{phrases: array<string, array{0: string, 1: string}>, longest: int, unspaced: list<string>, slugs: array<string, list<string>>}
     */
    private function build(bool $includeMature): array
    {
        $genres = $this->facets->labels($includeMature)['genres'];
        $dewey = DeweyClass::query()->pluck('name', 'code')->mapWithKeys(fn (string $name, int|string $code): array => [(string) $code => $name])->all();
        $slugs = [
            'type' => array_map('strval', array_keys($this->facets->types())),
            'genre' => array_map('strval', array_keys($genres)),
            'theme' => array_map('strval', array_keys($this->facets->themes())),
            'dewey' => array_map('strval', array_keys($dewey)),
            'length' => SearchFilters::LENGTHS,
        ];
        $allowed = array_map(fn (array $values): array => array_fill_keys($values, true), $slugs);

        $rows = [
            ...$this->csv('query_synonyms.csv'),
            ...$this->named('type', $this->facets->types()),
            ...$this->named('type', array_combine(array_keys($this->facets->types()), array_keys($this->facets->types()))),
            ...$this->named('genre', $genres),
            ...$this->named('theme', $this->facets->themes()),
        ];

        // A subject keyword ("history") names a nonfiction subject more precisely than the provider maps, which file all of them under the nonfiction family.
        foreach ($this->csv('dewey_subject_keywords.csv') as [$keyword, $code]) {
            $rows[] = [$keyword, 'dewey', $code];
        }

        foreach (self::PROVIDER_FILES as $provider) {
            foreach ($this->csv("taxonomy/{$provider}.csv") as [$term, $kind, $slug]) {
                if (isset(self::PROVIDER_KINDS[$kind])) {
                    $rows[] = [$term, self::PROVIDER_KINDS[$kind], $slug];
                }
            }
        }

        foreach ($this->csv('taxonomy/legacy.csv') as [, $oldSlug, $kind, $slug]) {
            $rows[] = [str_replace('-', ' ', $oldSlug), $kind, $slug];
        }

        $rows = [...$rows, ...$this->named('dewey', $dewey)];

        $phrases = [];

        foreach ($rows as [$phrase, $kind, $slug]) {
            $key = self::key(self::words($phrase));

            if ($key === '' || isset($phrases[$key]) || ! isset($allowed[$kind][$slug]) || ProhibitedContent::matches($phrase)) {
                continue;
            }

            $phrases[$key] = [$kind, $slug];
        }

        return [
            'phrases' => $phrases,
            'longest' => max([1, ...array_map(fn (string $key): int => substr_count($key, ' ') + 1, array_keys($phrases))]),
            'unspaced' => array_values(array_filter(array_map('strval', array_keys($phrases)), fn (string $key): bool => preg_match(self::UNSPACED, $key) === 1)),
            'slugs' => $slugs,
        ];
    }

    /**
     * @param  array<int|string, string>  $names  value to name
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function named(string $kind, array $names): array
    {
        return array_map(fn (int|string $value, string $name): array => [$name, $kind, (string) $value], array_keys($names), array_values($names));
    }

    /**
     * The rows of a CSV file under `database/data/`, header skipped.
     *
     * @return list<list<string>>
     */
    private function csv(string $path): array
    {
        $handle = fopen(database_path("data/{$path}"), 'r');

        if ($handle === false) {
            return [];
        }

        fgetcsv($handle, escape: '');
        $rows = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            if (count($row) >= 2 && $row[0] !== null) {
                $rows[] = array_map('strval', $row);
            }
        }

        fclose($handle);

        return $rows;
    }
}
