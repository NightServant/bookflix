<?php

namespace App\Discovery\Search;

/**
 * What a reader's words name in the taxonomy (see QueryParser). The types, genres, themes and Dewey code are filter values HybridSearch may apply;
 * the length hint is only carried, and `semanticText` is the query without the words that became the type or the length, for the semantic leg.
 */
final readonly class QueryIntent
{
    /**
     * @param  list<string>  $mediaTypes  media type slugs
     * @param  list<string>  $genres  genre or genre family slugs
     * @param  list<string>  $themes  theme slugs
     * @param  string|null  $deweyCode  one class code, as SearchFilters takes it
     * @param  string|null  $lengthHint  short|medium|long
     */
    public function __construct(
        public array $mediaTypes = [],
        public array $genres = [],
        public array $themes = [],
        public ?string $deweyCode = null,
        public ?string $lengthHint = null,
        public string $semanticText = '',
    ) {}

    /** The longest Dewey code or length hint taken from untrusted input; a real one is a few characters. */
    private const MAX_CODE_LENGTH = 16;

    /**
     * An intent from untrusted input (a cache entry, a Livewire payload, anything handed to HybridSearch). Lists keep string values only,
     * de-duplicated and capped as SearchFilters caps its own; the Dewey code is taken only as a short string, the length hint only when it is a
     * length bucket, the semantic text capped at the search box's length; unknown keys are ignored. Never throws.
     *
     * @param  array<mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $code = $input['deweyCode'] ?? null;
        $text = $input['semanticText'] ?? '';

        return new self(
            mediaTypes: self::strings($input['mediaTypes'] ?? [], SearchFilters::MAX_VALUES),
            genres: self::strings($input['genres'] ?? [], SearchFilters::MAX_GENRES),
            themes: self::strings($input['themes'] ?? [], SearchFilters::MAX_VALUES),
            deweyCode: self::valid($code) && $code !== '' && strlen($code) <= self::MAX_CODE_LENGTH ? $code : null,
            lengthHint: in_array($input['lengthHint'] ?? null, SearchFilters::LENGTHS, true) ? $input['lengthHint'] : null,
            semanticText: self::valid($text) ? mb_substr($text, 0, HybridSearch::MAX_QUERY_LENGTH) : '',
        );
    }

    /**
     * Every field, in the shape the constructor takes back as named arguments (the cache and `search_queries.intent` store this).
     *
     * @return array{mediaTypes: list<string>, genres: list<string>, themes: list<string>, deweyCode: string|null, lengthHint: string|null, semanticText: string}
     */
    public function toArray(): array
    {
        return [
            'mediaTypes' => $this->mediaTypes,
            'genres' => $this->genres,
            'themes' => $this->themes,
            'deweyCode' => $this->deweyCode,
            'lengthHint' => $this->lengthHint,
            'semanticText' => $this->semanticText,
        ];
    }

    /**
     * The distinct non-empty strings of a list, at most $limit of them; anything else (an array, a number, a non-list) is dropped.
     *
     * @return list<string>
     */
    private static function strings(mixed $values, int $limit): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $value) {
            if (self::valid($value) && $value !== '' && ! isset($strings[$value])) {
                $strings[$value] = true;

                if (count($strings) === $limit) {
                    break;
                }
            }
        }

        return array_map(strval(...), array_keys($strings));
    }

    /**
     * A string that is valid UTF-8 (invalid UTF-8 would reach json_encode and Postgres as a 500).
     *
     * @phpstan-assert-if-true string $value
     */
    private static function valid(mixed $value): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8');
    }
}
