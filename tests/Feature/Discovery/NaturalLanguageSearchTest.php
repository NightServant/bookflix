<?php

use App\Discovery\Search\HybridSearch;
use App\Discovery\Search\QueryIntent;
use App\Discovery\Search\QueryParser;
use App\Discovery\Search\SearchFilters;
use App\Discovery\Search\SearchLogger;
use App\Discovery\Search\SearchResults;
use App\Discovery\Search\SearchTextBuilder;
use App\Models\Genre;
use App\Models\Media;
use App\Models\SearchQuery;
use App\Models\Theme;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Database\Seeders\MediaTypeSeeder;
use Database\Seeders\TaxonomySeeder;

beforeEach(function () {
    $this->seed([MediaTypeSeeder::class, TaxonomySeeder::class, DeweySeeder::class]);

    $story = function (string $title, string $type, array $genres = [], array $themes = []): Media {
        $media = Media::factory()->ofType($type)->create(['title' => $title]);
        $media->genres()->attach(Genre::whereIn('slug', $genres)->pluck('id'));
        $media->themes()->attach(Theme::whereIn('slug', $themes)->pluck('id'));
        $media->update(['search_text' => app(SearchTextBuilder::class)->build($media)]);

        return $media;
    };

    $this->shadow = $story('Shadow Court', 'manga', ['dark-fantasy'], ['political-intrigue']);
    $this->bright = $story('Bright Court', 'novel', ['fantasy']);
});

function intentSearch(string $query, QueryIntent $intent, ?SearchFilters $filters = null, ?User $user = null): SearchResults
{
    return app(HybridSearch::class)->search($query, $filters ?? new SearchFilters, $user, intent: $intent);
}

test('intent filters apply when results remain', function () {
    $results = intentSearch('court', new QueryIntent(mediaTypes: ['manga'], genres: ['dark-fantasy'], themes: ['political-intrigue']));

    expect($results->pluck('title')->all())->toBe(['Shadow Court'])
        ->and($results->appliedIntent)->toBe(['mediaTypes' => ['manga'], 'genres' => ['dark-fantasy'], 'themes' => ['political-intrigue']])
        ->and($results->suggestedIntent)->toBe([]);
});

test('an intent filter that would empty the results is only suggested', function () {
    $results = intentSearch('court', new QueryIntent(mediaTypes: ['webcomic']));

    expect($results->total())->toBe(2)
        ->and($results->appliedIntent)->toBe([])
        ->and($results->suggestedIntent)->toBe(['mediaTypes' => ['webcomic']]);
});

test('relaxation drops the least telling filters first and keeps the rest', function () {
    classified($this->bright, '940');

    $results = intentSearch('court', new QueryIntent(mediaTypes: ['manga'], themes: ['revenge'], deweyCode: '940'));

    expect($results->pluck('title')->all())->toBe(['Shadow Court'])
        ->and($results->appliedIntent)->toBe(['mediaTypes' => ['manga']])
        ->and($results->suggestedIntent)->toBe(['themes' => ['revenge'], 'deweyCode' => '940']);
});

test('a dewey intent applies like the filter', function () {
    classified($this->bright, '940');

    $results = intentSearch('court', new QueryIntent(deweyCode: '900'));

    expect($results->pluck('title')->all())->toBe(['Bright Court'])
        ->and($results->appliedIntent)->toBe(['deweyCode' => '900']);
});

test('nothing is applied when the text finds nothing', function () {
    $results = intentSearch('zeppelin', new QueryIntent(mediaTypes: ['manga']));

    expect($results->total())->toBe(0)
        ->and($results->appliedIntent)->toBe([])
        ->and($results->suggestedIntent)->toBe(['mediaTypes' => ['manga']]);
});

test('the reader explicit filters win over intent', function () {
    $results = intentSearch('court', new QueryIntent(mediaTypes: ['manga'], genres: ['fantasy']), new SearchFilters(mediaTypes: ['novel']));

    expect($results->pluck('title')->all())->toBe(['Bright Court'])
        ->and($results->appliedIntent)->toBe(['genres' => ['fantasy']])
        ->and($results->suggestedIntent)->toBe([]);
});

test('values outside the reader vocabulary are neither applied nor named', function () {
    $erotic = matureStory('Court of Desire');
    $erotic->update(['search_text' => 'court of desire']);
    $erotica = Genre::where('slug', 'erotica')->sole();
    $erotic->genres()->attach($erotica);

    $guest = intentSearch('court', new QueryIntent(genres: ['erotica', 'no-such-genre']));
    $mature = intentSearch('court', new QueryIntent(genres: ['erotica']), user: User::factory()->showsMature()->create());

    expect($guest->total())->toBe(2)
        ->and($guest->appliedIntent)->toBe([])
        ->and($guest->suggestedIntent)->toBe([])
        ->and($mature->pluck('title')->all())->toBe(['Court of Desire'])
        ->and($mature->appliedIntent)->toBe(['genres' => ['erotica']]);
});

test('without intent the search is unchanged', function () {
    $results = app(HybridSearch::class)->search('court', new SearchFilters, null);

    expect($results->total())->toBe(2)
        ->and($results->appliedIntent)->toBe([])
        ->and($results->suggestedIntent)->toBe([]);
});

test('a parsed query feeds the original string to the keyword leg and its intent to the filters', function () {
    $intent = app(QueryParser::class)->parse('dark fantasy manga court');

    $withType = intentSearch('dark fantasy manga court', $intent);
    $withoutType = intentSearch('dark fantasy court', app(QueryParser::class)->parse('dark fantasy court'));

    expect($intent->mediaTypes)->toBe(['manga'])
        ->and($withType->total())->toBe(0) // no story's text says "manga": the keyword leg reads the reader's words, not the intent's
        ->and($withType->suggestedIntent)->toBe(['mediaTypes' => ['manga'], 'genres' => ['dark-fantasy']])
        ->and($withoutType->pluck('title')->all())->toBe(['Shadow Court'])
        ->and($withoutType->appliedIntent)->toBe(['genres' => ['dark-fantasy']]);
});

test('the parsed intent is logged with the search', function () {
    $intent = app(QueryParser::class)->parse('dark fantasy manga');

    app(SearchLogger::class)->log('dark fantasy manga', new SearchFilters, 0, null, null, $intent);
    app(SearchLogger::class)->log('court', new SearchFilters, 2, null, null);

    [$parsed, $plain] = SearchQuery::orderBy('id')->get()->all();

    expect($parsed->intent)->toEqual($intent->toArray()) // jsonb keeps its own key order
        ->and($parsed->intent['mediaTypes'])->toBe(['manga'])
        ->and($parsed->intent['genres'])->toBe(['dark-fantasy'])
        ->and($plain->intent)->toBeNull();
});
