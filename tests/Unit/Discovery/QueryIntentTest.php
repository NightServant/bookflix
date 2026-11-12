<?php

use App\Discovery\Search\HybridSearch;
use App\Discovery\Search\QueryIntent;
use App\Discovery\Search\SearchFilters;

test('an intent from an array keeps distinct strings only', function () {
    $intent = QueryIntent::fromArray([
        'mediaTypes' => ['manga', ['novel'], 3, 'manga', ''],
        'genres' => [['fantasy'], 'fantasy', true, null, 'horror'],
        'themes' => 'revenge',
        'deweyCode' => ['900'],
        'lengthHint' => 'tiny',
        'semanticText' => ['dark'],
        'unknown' => 'ignored',
    ]);

    expect($intent->toArray())->toBe([
        'mediaTypes' => ['manga'],
        'genres' => ['fantasy', 'horror'],
        'themes' => [],
        'deweyCode' => null,
        'lengthHint' => null,
        'semanticText' => '',
    ]);
});

test('numeric strings stay strings', function () {
    expect(QueryIntent::fromArray(['themes' => ['123', '123']])->themes)->toBe(['123']);
});

test('an intent from an array is capped like the filters', function () {
    $many = array_map(fn (int $i): string => "slug-{$i}", range(1, 2000));

    $intent = QueryIntent::fromArray([
        'mediaTypes' => $many,
        'genres' => [...array_fill(0, 200_000, 'fantasy'), ...$many],
        'themes' => $many,
        'deweyCode' => str_repeat('9', 100),
        'semanticText' => str_repeat('a', 5000),
    ]);

    expect($intent->mediaTypes)->toHaveCount(SearchFilters::MAX_VALUES)
        ->and($intent->genres)->toHaveCount(SearchFilters::MAX_GENRES)
        ->and($intent->genres[0])->toBe('fantasy')
        ->and($intent->themes)->toHaveCount(SearchFilters::MAX_VALUES)
        ->and($intent->deweyCode)->toBeNull()
        ->and(mb_strlen($intent->semanticText))->toBe(HybridSearch::MAX_QUERY_LENGTH);
});

test('a valid intent survives the round trip', function () {
    $intent = new QueryIntent(['manga'], ['dark-fantasy'], ['political-intrigue'], '940', 'short', 'dark fantasy');

    expect(QueryIntent::fromArray($intent->toArray()))->toEqual($intent);
});

test('invalid utf-8 is dropped', function () {
    expect(QueryIntent::fromArray(['genres' => ["fant\xB1sy", 'horror'], 'semanticText' => "bad \xB1"])->toArray())
        ->toMatchArray(['genres' => ['horror'], 'semanticText' => '']);
});
