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
        // `tale` is in every story's text but in no title, so a search for it never looks like the reader named a title (see the title guard).
        $media->update(['search_text' => app(SearchTextBuilder::class)->build($media).' tale']);

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
    $results = intentSearch('tale', new QueryIntent(mediaTypes: ['manga'], genres: ['dark-fantasy'], themes: ['political-intrigue']));

    expect($results->pluck('title')->all())->toBe(['Shadow Court'])
        ->and($results->appliedIntent)->toBe(['mediaTypes' => ['manga'], 'genres' => ['dark-fantasy'], 'themes' => ['political-intrigue']])
        ->and($results->suggestedIntent)->toBe([]);
});

test('an intent filter that would empty the results is only suggested', function () {
    $results = intentSearch('tale', new QueryIntent(mediaTypes: ['webcomic']));

    expect($results->total())->toBe(2)
        ->and($results->appliedIntent)->toBe([])
        ->and($results->suggestedIntent)->toBe(['mediaTypes' => ['webcomic']]);
});

test('relaxation drops the least telling filters first and keeps the rest', function () {
    classified($this->bright, '940');

    $results = intentSearch('tale', new QueryIntent(mediaTypes: ['manga'], themes: ['revenge'], deweyCode: '940'));

    expect($results->pluck('title')->all())->toBe(['Shadow Court'])
        ->and($results->appliedIntent)->toBe(['mediaTypes' => ['manga']])
        ->and($results->suggestedIntent)->toBe(['themes' => ['revenge'], 'deweyCode' => '940']);
});

test('a dewey intent applies like the filter', function () {
    classified($this->bright, '940');

    $results = intentSearch('tale', new QueryIntent(deweyCode: '900'));

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
    $erotic->update(['search_text' => 'court of desire tale']);
    $erotica = Genre::where('slug', 'erotica')->sole();
    $erotic->genres()->attach($erotica);

    $guest = intentSearch('tale', new QueryIntent(genres: ['erotica', 'no-such-genre']));
    $mature = intentSearch('tale', new QueryIntent(genres: ['erotica']), user: User::factory()->showsMature()->create());

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

/**
 * A searchable story, made to pass every group of the intent when one is given (its type, genres, themes and Dewey class), otherwise of a
 * type no intent names, with no genre, theme or class.
 */
function storyFor(string $title, ?QueryIntent $intent = null, string $extraText = ''): Media
{
    $media = $intent?->mediaTypes ? Media::factory()->ofType($intent->mediaTypes[0])->create(['title' => $title]) : Media::factory()->create(['title' => $title]);
    $media->genres()->attach(Genre::whereIn('slug', $intent->genres ?? [])->pluck('id'));
    $media->themes()->attach(Theme::whereIn('slug', $intent->themes ?? [])->pluck('id'));
    $intent?->deweyCode === null || classified($media, $intent->deweyCode);
    $media->update(['search_text' => trim(app(SearchTextBuilder::class)->build($media).' '.$extraText)]);

    return $media;
}

test('a query that is the title of a work the intent would hide only suggests the intent', function (string $query, string $decoy) {
    $intent = app(QueryParser::class)->parse($query);
    storyFor($decoy, $intent);
    storyFor($query);

    $results = intentSearch($query, $intent);
    $groups = array_filter(['mediaTypes' => $intent->mediaTypes, 'genres' => $intent->genres, 'themes' => $intent->themes, 'deweyCode' => $intent->deweyCode]);

    expect($groups)->not->toBe([])
        ->and($results->pluck('title')->first())->toBe($query)
        ->and($results->appliedIntent)->toBe([])
        ->and($results->suggestedIntent)->toBe($groups);
})->with([
    ['Fantasy Island', 'Fantasy Islands Saga'],
    ['Manga Guide to Databases', 'Manga Guide Workbook'],
    ['A History of Violence', 'A History of Rome'],
    ['Death Note', 'Notes on Death'],
    ['Ghost in the Shell', 'Ghost in the House'],
    ['Crime and Punishment', 'Crime and Consequence'],
    ['A Brief History of Time', 'A Brief History of Rome'],
]);

test('a descriptive query still applies its intent', function () {
    $query = 'dark fantasy manga with political intrigue';
    $this->shadow->update(['search_text' => $this->shadow->search_text.' '.$query]);
    $this->bright->update(['search_text' => $this->bright->search_text.' '.$query]);

    $results = intentSearch($query, app(QueryParser::class)->parse($query));

    expect($results->pluck('title')->all())->toBe(['Shadow Court'])
        ->and($results->appliedIntent)->toBe(['mediaTypes' => ['manga'], 'genres' => ['dark-fantasy'], 'themes' => ['political-intrigue']]);
});

test('a title whose work passes the intent still applies it', function () {
    $intent = app(QueryParser::class)->parse('Dark Fantasy Manga');
    storyFor('Dark Fantasy Manga', $intent);
    storyFor('Plain Story', extraText: 'dark fantasy manga');

    $results = intentSearch('Dark Fantasy Manga', $intent);

    expect($results->pluck('title')->all())->toBe(['Dark Fantasy Manga'])
        ->and($results->appliedIntent)->toBe(['mediaTypes' => ['manga'], 'genres' => ['dark-fantasy']]);
});

test('a title the reader may not see never switches the guard on', function (string $hidden) {
    $intent = app(QueryParser::class)->parse('Fantasy Island');
    storyFor('Fantasy Islands Saga', $intent);
    $named = storyFor('Fantasy Island');

    match ($hidden) {
        'mature' => $named->genres()->attach(Genre::factory()->mature()->create()),
        'soft deleted' => $named->delete(),
        'merged' => $named->update(['merged_into_id' => $this->shadow->id]),
    };

    $results = intentSearch('Fantasy Island', $intent, user: User::factory()->create());

    expect($results->pluck('title')->all())->toBe(['Fantasy Islands Saga'])
        ->and($results->appliedIntent)->toBe(['genres' => ['fantasy']]);
})->with(['mature', 'soft deleted', 'merged']);

test('the guard reads alternative titles and whole words only', function () {
    $intent = app(QueryParser::class)->parse('Fantasy Island');
    storyFor('Fantasy Islands Saga', $intent);
    $named = storyFor('Isla de Fantasía');
    $named->update(['alternative_titles' => ['Fantasy Island'], 'search_text' => $named->search_text.' fantasy island']);

    expect(intentSearch('Fantasy Island', $intent)->appliedIntent)->toBe([]);

    $named->update(['alternative_titles' => ['Fantasy Islanders']]);

    expect(intentSearch('Fantasy Island', $intent)->appliedIntent)->toBe(['genres' => ['fantasy']]);
});

test('an intent from untrusted input never breaks the search', function () {
    $nested = intentSearch('tale', new QueryIntent(mediaTypes: [['manga']], genres: [['fantasy'], 7, 'fantasy'], themes: [null, 1.5], deweyCode: '9'.str_repeat('0', 100)));
    $huge = intentSearch('tale', new QueryIntent(genres: array_fill(0, 200_000, 'fantasy')));

    expect($nested->appliedIntent)->toBe(['genres' => ['fantasy']])
        ->and($huge->appliedIntent)->toBe(['genres' => ['fantasy']]);
});

test('regular expression and like characters in a query are literal to the guard', function (string $query) {
    storyFor('Fantasy Islands Saga', new QueryIntent(genres: ['fantasy']));
    storyFor('Fantasy Island');

    expect(fn () => intentSearch($query, new QueryIntent(genres: ['fantasy'])))->not->toThrow(Throwable::class)
        ->and(intentSearch($query, new QueryIntent(genres: ['fantasy']))->appliedIntent)->toBe(['genres' => ['fantasy']]);
})->with(['fantasy (island', 'fantasy isl.nd', 'fantasy [a-z]*', 'fantasy \\', 'fantasy %', 'fantasy _sland', 'fantasy island$|']);
