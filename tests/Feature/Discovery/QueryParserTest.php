<?php

use App\Catalog\Ingestion\ProhibitedContent;
use App\Discovery\Search\QueryIntent;
use App\Discovery\Search\QueryParser;
use App\Discovery\Search\QueryVocabulary;
use App\Discovery\Search\RuleBasedQueryParser;
use App\Discovery\Search\SearchFilters;
use App\Discovery\Search\TaxonomyFacets;
use App\Models\DeweyClass;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Database\Seeders\MediaTypeSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([MediaTypeSeeder::class, TaxonomySeeder::class, DeweySeeder::class]);
});

function intentOf(string $query, ?User $viewer = null): QueryIntent
{
    return app(QueryParser::class)->parse($query, $viewer);
}

/**
 * The rows of `database/data/query_synonyms.csv`.
 *
 * @return list<array{phrase: string, kind: string, slug: string}>
 */
function synonymRows(): array
{
    $handle = fopen(database_path('data/query_synonyms.csv'), 'r');
    expect(fgetcsv($handle, escape: ''))->toBe(['phrase', 'kind', 'slug']);
    $rows = [];

    while (($row = fgetcsv($handle, escape: '')) !== false) {
        $rows[] = ['phrase' => $row[0], 'kind' => $row[1], 'slug' => $row[2]];
    }

    fclose($handle);

    return $rows;
}

test('the rule based parser is the default', function () {
    expect(app(QueryParser::class))->toBeInstanceOf(RuleBasedQueryParser::class);
});

test('a natural language query names a type a genre and a theme from the taxonomy', function () {
    $intent = intentOf('dark fantasy manga with political intrigue');

    expect($intent->mediaTypes)->toBe(['manga'])
        ->and($intent->genres)->toBe(['dark-fantasy'])
        ->and($intent->themes)->toBe(['political-intrigue'])
        ->and($intent->semanticText)->toContain('dark')->toContain('fantasy')->toContain('political intrigue')->not->toContain('manga');
});

test('a short read is a length hint', function () {
    $intent = intentOf('short science-fiction novel I can finish this weekend');

    expect($intent->mediaTypes)->toBe(['novel'])
        ->and($intent->genres)->toBe(['science-fiction'])
        ->and($intent->lengthHint)->toBe('short');
});

test('an anchor title is kept for the semantic leg', function () {
    $intent = intentOf('something similar to Dune but more philosophical');

    expect($intent->semanticText)->toContain('dune')
        ->and($intent->mediaTypes)->toBe([]);
});

test('a dewey class is named by its subject', function () {
    expect(intentOf('history books')->deweyCode)->toBe('900')
        ->and(intentOf('european history')->deweyCode)->toBe('940');
});

test('plural case accents scripts and multiword synonyms are understood', function (string $query, string $field, array $expected) {
    expect(intentOf($query)->{$field})->toBe($expected);
})->with([
    ['Mangas', 'mediaTypes', ['manga']],
    ['SCI-FI', 'genres', ['science-fiction']],
    ['fantasía', 'genres', ['fantasy']],
    ['漫画', 'mediaTypes', ['manga']],
    ['進撃の巨人の漫画', 'mediaTypes', ['manga']],
    ['graphic novels', 'mediaTypes', ['graphic-novel']],
    ['GRAPHIC   Novels', 'mediaTypes', ['graphic-novel']],
    ['cozy mysteries', 'genres', ['cozy-mystery']],
    ['non-fiction', 'mediaTypes', ['nonfiction']],
]);

test('negated words are ignored', function () {
    expect(intentOf('not romance')->genres)->toBe([])
        ->and(intentOf('fantasy without romance')->genres)->toBe(['fantasy'])
        ->and(intentOf('fantasy without romance')->semanticText)->not->toContain('romance')
        ->and(intentOf('romance not horror')->genres)->toBe(['romance']);
});

test('degenerate queries return an intent without exceptions', function (string $query) {
    expect(intentOf($query))->toBeInstanceOf(QueryIntent::class);
})->with([
    'empty' => [''],
    'blank' => ['   '],
    'emoji' => ['🐉🔥📚'],
    'punctuation' => ['!!! ??? ---'],
    'invalid utf-8' => ["fantasy \xB1\x31"],
    'long' => [str_repeat('a', 5000)],
    'long words' => [str_repeat('dark fantasy ', 400)],
]);

test('emoji around a word do not hide it', function () {
    expect(intentOf('📚 fantasy 🐉')->genres)->toBe(['fantasy'])
        ->and(intentOf('')->semanticText)->toBe('');
});

test('a reader without mature access never gets a mature genre parsed', function () {
    $mature = User::factory()->showsMature()->create();

    expect(intentOf('erotica', $mature)->genres)->toBe(['erotica'])
        ->and(intentOf('erotica')->genres)->toBe([])
        ->and(intentOf('erotica', User::factory()->create())->genres)->toBe([])
        ->and(app(QueryVocabulary::class)->slugs(null)['genre'])->not->toContain('erotica');
});

test('the parse is cached per normalized query', function () {
    intentOf('Dark Fantasy manga');

    DB::enableQueryLog();
    $intent = intentOf('  dark   FANTASY Manga ');

    expect(DB::getQueryLog())->toBe([])
        ->and($intent->genres)->toBe(['dark-fantasy']);
});

test('parsing makes at most two queries once the vocabulary is cached', function () {
    $reader = User::factory()->create();
    intentOf('warm up', $reader);

    $reader = User::find($reader->id);
    DB::enableQueryLog();
    $intent = intentOf('dark fantasy manga with political intrigue', $reader);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(2)
        ->and($intent->mediaTypes)->toBe(['manga']);
});

test('no synonym is a prohibited term', function () {
    $prohibited = collect(synonymRows())->filter(fn (array $row): bool => ProhibitedContent::matches($row['phrase']));

    expect($prohibited->all())->toBe([]);
});

test('every synonym points at a real taxonomy value', function () {
    $facets = app(TaxonomyFacets::class);
    $known = [
        'type' => array_keys($facets->types()),
        'genre' => array_keys($facets->labels(includeMature: true)['genres']),
        'theme' => array_keys($facets->themes()),
        'dewey' => DeweyClass::pluck('code')->all(),
        'length' => SearchFilters::LENGTHS,
    ];

    foreach (synonymRows() as $row) {
        expect($known)->toHaveKey($row['kind'])
            ->and($known[$row['kind']])->toContain($row['slug']);
    }
});
