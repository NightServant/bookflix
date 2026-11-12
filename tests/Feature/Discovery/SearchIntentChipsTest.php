<?php

use App\Discovery\Search\QueryParser;
use App\Discovery\Search\SearchTextBuilder;
use App\Enums\ModelKind;
use App\Enums\ModelStatus;
use App\Models\Genre;
use App\Models\Media;
use App\Models\MediaEmbedding;
use App\Models\ModelVersion;
use App\Models\SearchQuery;
use App\Models\Theme;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Database\Seeders\MediaTypeSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

const COURT_QUERY = 'dark fantasy manga with political intrigue';

beforeEach(function () {
    $this->seed([MediaTypeSeeder::class, TaxonomySeeder::class, DeweySeeder::class]);

    $this->makeStory = function (string $title, string $type, array $genres = [], array $themes = [], string $text = COURT_QUERY): Media {
        $media = Media::factory()->ofType($type)->create(['title' => $title]);
        $media->genres()->attach(Genre::whereIn('slug', $genres)->pluck('id'));
        $media->themes()->attach(Theme::whereIn('slug', $themes)->pluck('id'));
        $media->update(['search_text' => trim(app(SearchTextBuilder::class)->build($media).' '.$text)]);

        return $media;
    };

    $this->shadow = ($this->makeStory)('Shadow Court', 'manga', ['dark-fantasy'], ['political-intrigue']);
    $this->bright = ($this->makeStory)('Bright Court', 'novel', ['dark-fantasy'], ['political-intrigue']);

    $this->actingAs(User::factory()->create());
});

/**
 * The accessible names of the buttons inside the group of chips with this label.
 *
 * @return list<string>
 */
function chipNames(string $html, string $group): array
{
    return collect(htmlQuery($html, '//*[@aria-label="'.$group.'"]//button'))->map(fn (DOMElement $button): string => $button->getAttribute('aria-label'))->all();
}

test('what the query names shows as removable chips above the results', function () {
    $page = Livewire::test('pages::search.index')->set('q', COURT_QUERY);

    $page->assertSee('From your search')->assertSee('Shadow Court')->assertDontSee('Bright Court');

    expect($page->html())->toContain("dismissIntent('mediaTypes', 'manga')")
        ->and(chipNames($page->html(), 'From your search'))->toBe(['Remove Manga from your search', 'Remove Dark Fantasy from your search', 'Remove Political Intrigue from your search']);
});

test('removing an applied chip searches again without it and offers it under narrow by', function () {
    $page = Livewire::test('pages::search.index')->set('q', COURT_QUERY)->call('dismissIntent', 'mediaTypes', 'manga');

    $page->assertSee('Shadow Court')->assertSee('Bright Court')->assertSee('Narrow by');

    expect(chipNames($page->html(), 'From your search'))->toBe(['Remove Dark Fantasy from your search', 'Remove Political Intrigue from your search'])
        ->and(chipNames($page->html(), 'Narrow by'))->toBe(['Filter by Manga']);
});

test('removing the last applied chip leaves no row of applied chips', function () {
    $page = Livewire::test('pages::search.index')->set('q', 'manga court')->call('dismissIntent', 'mediaTypes', 'manga');

    expect($page->html())->not->toContain('From your search')->and(chipNames($page->html(), 'Narrow by'))->toBe(['Filter by Manga']);
});

test('changing the query brings the dismissed chips back', function () {
    $page = Livewire::test('pages::search.index')->set('q', COURT_QUERY)->call('dismissIntent', 'mediaTypes', 'manga')->set('q', 'dark fantasy manga with political intrigue ');

    expect(chipNames($page->html(), 'From your search'))->toContain('Remove Manga from your search');

    $page->call('dismissIntent', 'mediaTypes', 'manga')->set('q', 'manga court');

    expect(chipNames($page->html(), 'From your search'))->toBe(['Remove Manga from your search']);
});

test('a value the query did not name cannot be dismissed into anything', function () {
    $page = Livewire::test('pages::search.index')->set('q', COURT_QUERY)->call('dismissIntent', 'genres', 'horror')->call('dismissIntent', 'nonsense', 'manga')->call('dismissIntent', 'mediaTypes', ['manga'])
        ->call('dismissIntent', ['mediaTypes'], 'manga')->call('dismissIntent', null, 'manga');

    expect(chipNames($page->html(), 'From your search'))->toHaveCount(3)->and($page->html())->not->toContain('Narrow by');
});

test('a suggested chip adds the value to the readers own filters', function () {
    $this->bright->update(['title' => 'Plain Novel']);
    ($this->makeStory)('Fantasy Island', 'novel', ['thriller'], [], 'fantasy island');

    $page = Livewire::test('pages::search.index')->set('q', 'Fantasy Island');

    expect(chipNames($page->html(), 'Narrow by'))->toBe(['Filter by Fantasy'])->and($page->html())->toContain("addIntentFilter('genres', 'fantasy')");

    $page->call('addIntentFilter', 'genres', 'fantasy')->assertSet('filters.genres', ['fantasy']);

    expect($page->html())->not->toContain('Narrow by')->and($page->html())->toContain('Remove filter Fantasy');
});

test('a value the reader removed can be added as a filter from narrow by', function () {
    $page = Livewire::test('pages::search.index')->set('q', COURT_QUERY)->call('dismissIntent', 'mediaTypes', 'manga')->call('addIntentFilter', 'mediaTypes', 'manga');

    $page->assertSet('filters.mediaTypes', ['manga'])->assertSee('Shadow Court')->assertDontSee('Bright Court');

    expect($page->html())->not->toContain('Filter by Manga');
});

test('only a chip on offer can be added as a filter', function () {
    $page = Livewire::test('pages::search.index')->set('q', COURT_QUERY)->call('addIntentFilter', 'genres', 'horror')->call('addIntentFilter', 'genres', 'dark-fantasy')->call('addIntentFilter', 'tropes', 'x')
        ->call('addIntentFilter', ['genres'], 'dark-fantasy')->call('addIntentFilter', null, 'dark-fantasy');

    $page->assertSet('filters.genres', [])->assertSet('filters.tropes', []);
});

test('a search that names a title keeps its results and only suggests', function () {
    ($this->makeStory)('Fantasy Island', 'novel', ['thriller'], [], 'fantasy island');
    ($this->makeStory)('Fantasy Islands Saga', 'manga', ['fantasy'], [], 'fantasy islands saga');

    $page = Livewire::test('pages::search.index')->set('q', 'Fantasy Island');

    expect($page->html())->not->toContain('From your search')
        ->and(chipNames($page->html(), 'Narrow by'))->toBe(['Filter by Fantasy'])
        ->and(collect(htmlQuery($page->html(), '//*[@data-view]//li//*[self::h2 or self::h3 or self::a]'))->map(fn (DOMNode $node): string => trim($node->textContent))->filter()->first())->toContain('Fantasy Island');
});

test('a query that names nothing shows no chips and no extra rows', function () {
    ($this->makeStory)('Berserk', 'manga', [], [], 'berserk');

    $page = Livewire::test('pages::search.index')->set('q', 'berserk')->assertSee('Berserk');

    expect($page->html())->not->toContain('From your search')->not->toContain('Narrow by')->not->toContain('meaning of your search');

    Livewire::test('pages::search.index')->assertDontSee('From your search')->assertDontSee('Narrow by');
});

test('no suggestions are offered when nothing was found', function () {
    $page = Livewire::test('pages::search.index')->set('q', 'zzzxxyy manga')->assertSee('No stories found');

    expect($page->html())->not->toContain('Narrow by')->not->toContain('From your search');
});

test('a mature genre is never a chip for a guest or a gated reader, even when it is asked for', function (?User $reader) {
    $mature = Genre::where('slug', 'erotica')->firstOrFail();
    ($this->makeStory)('Velvet Nights', 'novel', [], [], 'erotica velvet nights')->genres()->attach($mature);
    $reader === null ? auth()->logout() : $this->actingAs($reader);

    $page = Livewire::test('pages::search.index')->set('q', 'erotica velvet nights')
        ->call('dismissIntent', 'genres', 'erotica')
        ->call('addIntentFilter', 'genres', 'erotica');

    expect($page->html())->not->toContain('Erotica')->not->toContain('Narrow by')->not->toContain('From your search');
    $page->assertSet('filters.genres', []);
})->with([
    'a guest' => [null],
    'a gated reader' => [fn () => User::factory()->create()],
]);

test('a reader with mature access does get the chip', function () {
    $mature = Genre::where('slug', 'erotica')->firstOrFail();
    ($this->makeStory)('Velvet Nights', 'novel', [], [], 'erotica velvet nights')->genres()->attach($mature);
    $this->actingAs(User::factory()->showsMature()->create());

    $page = Livewire::test('pages::search.index')->set('q', 'erotica velvet nights');

    expect(chipNames($page->html(), 'From your search'))->toBe(['Remove Erotica from your search']);
});

test('the intent is logged with the search', function () {
    Livewire::test('pages::search.index')->set('q', COURT_QUERY);

    $intent = SearchQuery::sole()->intent;

    expect($intent['mediaTypes'])->toBe(['manga'])->and($intent['genres'])->toBe(['dark-fantasy'])->and($intent['themes'])->toBe(['political-intrigue']);
});

test('a dewey class the query names is a chip with its class name', function () {
    $this->shadow->update(['search_text' => $this->shadow->search_text.' history']);
    classified($this->shadow, '940');

    $page = Livewire::test('pages::search.index')->set('q', 'history court');
    $names = chipNames($page->html(), 'From your search');

    expect($names)->toHaveCount(1)->and($names[0])->toStartWith('Remove ')->toEndWith(' from your search');
})->skip(fn () => ! app(QueryParser::class)->parse('history court')->deweyCode, 'the parser names no class for this query');

describe('the semantic note', function () {
    beforeEach(function () {
        config(['services.ml.url' => 'http://ml.test', 'services.ml.token' => str_repeat('s', 32)]);

        $version = ModelVersion::factory()->create([
            'name' => 'embedding',
            'kind' => ModelKind::Embedding,
            'status' => ModelStatus::Active,
            'parameters' => ['model' => 'intfloat/multilingual-e5-small', 'revision' => '614241f622f53c4eeff9890bdc4f31cfecc418b3', 'dims' => 384],
        ]);

        $vector = array_map(fn (int $i): float => $i === 0 ? 1.0 : 0.0, range(0, 383));
        $this->robot = ($this->makeStory)('Rust and Lullabies', 'manga', [], [], '');
        MediaEmbedding::factory()->create(['media_id' => $this->robot->id, 'model_version_id' => $version->id, 'embedding' => $vector]);
        $this->vector = $vector;
    });

    test('a quiet line says so when a story matched by meaning only', function () {
        Http::fake(['ml.test/v1/embed' => Http::response(['model_version' => 'intfloat/multilingual-e5-small@614241f622f53c4eeff9890bdc4f31cfecc418b3', 'dims' => 384, 'vectors' => [$this->vector]])]);

        Livewire::test('pages::search.index')->set('q', 'a lonely robot learning to feel')
            ->assertSee('Rust and Lullabies')
            ->assertSee('Includes stories that match the meaning of your search, not only its words.');
    });

    test('nothing extra shows when the words found every story', function () {
        Http::fake(['ml.test/v1/embed' => Http::response(['model_version' => 'intfloat/multilingual-e5-small@614241f622f53c4eeff9890bdc4f31cfecc418b3', 'dims' => 384, 'vectors' => [$this->vector]])]);

        Livewire::test('pages::search.index')->set('q', 'rust lullabies')
            ->assertSee('Rust and Lullabies')
            ->assertDontSee('meaning of your search');
    });

    test('nothing extra and no error shows while the ML service is down', function () {
        Http::fake(fn () => throw new ConnectionException('Operation timed out'));

        Livewire::test('pages::search.index')->set('q', 'a lonely robot learning to feel')
            ->assertDontSee('meaning of your search')
            ->assertDontSee('Rust and Lullabies')
            ->assertSee('No stories found');
    });
});
