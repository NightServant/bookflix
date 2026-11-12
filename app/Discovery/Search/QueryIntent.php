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
}
