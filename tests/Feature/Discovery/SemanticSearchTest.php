<?php

use App\Discovery\Search\HybridSearch;
use App\Discovery\Search\Legs\SemanticLeg;
use App\Discovery\Search\QueryIntent;
use App\Discovery\Search\SearchFilters;
use App\Discovery\Search\SearchResults;
use App\Discovery\Search\SearchTextBuilder;
use App\Enums\ModelKind;
use App\Enums\ModelStatus;
use App\Models\Genre;
use App\Models\Media;
use App\Models\MediaEmbedding;
use App\Models\ModelVersion;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Database\Seeders\MediaTypeSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const E5 = 'intfloat/multilingual-e5-small';
const E5_REVISION = '614241f622f53c4eeff9890bdc4f31cfecc418b3';

beforeEach(function () {
    config(['services.ml.url' => 'http://ml.test', 'services.ml.token' => str_repeat('s', 32)]);

    $this->version = embeddingVersion();
    $this->robot = embeddedStory('A lonely robot learns to love', unitVector([0 => 1.0]));
    $this->berserk = embeddedStory('Berserk', unitVector([1 => 1.0]));
});

/**
 * An `embedding` model version made by the e5 model the fake service answers with.
 *
 * @param  array<string, mixed>  $parameters
 */
function embeddingVersion(ModelStatus $status = ModelStatus::Active, array $parameters = []): ModelVersion
{
    return ModelVersion::factory()->create([
        'name' => 'embedding',
        'kind' => ModelKind::Embedding,
        'status' => $status,
        'parameters' => [...['model' => E5, 'revision' => E5_REVISION, 'dims' => 384], ...$parameters],
    ]);
}

/**
 * A 384-dimensional unit vector from its non-zero components (index => value).
 *
 * @param  array<int, float>  $components
 * @return list<float>
 */
function unitVector(array $components): array
{
    $norm = sqrt(array_sum(array_map(fn (float $x): float => $x * $x, $components)));

    return array_map(fn (int $i): float => ($components[$i] ?? 0.0) / $norm, range(0, 383));
}

/**
 * A searchable story with an embedding under the given (by default the active) version.
 *
 * @param  list<float>  $vector
 */
function embeddedStory(string $title, array $vector, ?ModelVersion $version = null, ?Media $media = null): Media
{
    $media ??= Media::factory()->create(['title' => $title]);
    $media->update(['search_text' => app(SearchTextBuilder::class)->build($media)]);
    MediaEmbedding::factory()->create(['media_id' => $media->id, 'model_version_id' => ($version ?? test()->version)->id, 'embedding' => $vector]);

    return $media;
}

/**
 * The service answers every embed request with this vector.
 *
 * @param  list<float>  $vector
 */
function fakeEmbedding(array $vector, string $modelVersion = E5.'@'.E5_REVISION, ?int $dims = null): void
{
    Http::fake(['ml.test/v1/embed' => Http::response(['model_version' => $modelVersion, 'dims' => $dims ?? count($vector), 'vectors' => [$vector]])]);
}

function semanticSearch(string $query, ?SearchFilters $filters = null, ?User $user = null, ?QueryIntent $intent = null): SearchResults
{
    return app(HybridSearch::class)->search($query, $filters ?? new SearchFilters, $user, intent: $intent);
}

test('semantic hits that keyword search misses are returned', function () {
    fakeEmbedding(unitVector([0 => 1.0, 2 => 0.1]));

    $results = semanticSearch('sad machine who feels');

    expect($results->pluck('title')->all())->toBe(['A lonely robot learns to love'])
        ->and($results->hasMore)->toBeFalse();
    Http::assertSent(fn (Request $request) => $request['texts'] === ['sad machine who feels'] && $request['kind'] === 'query');
});

test('semantic and keyword hits are fused', function () {
    config(['bookflix.search.semantic_min_similarity' => 0.60]);
    fakeEmbedding(unitVector([1 => 1.0, 0 => 0.95]));

    expect(semanticSearch('berserk')->pluck('title')->all())->toBe(['Berserk', 'A lonely robot learns to love']);
});

test('neighbours under the minimum similarity are dropped', function () {
    config(['bookflix.search.semantic_min_similarity' => 0.80]);
    fakeEmbedding(unitVector([0 => 0.7, 3 => 0.714]));

    expect(semanticSearch('sad machine who feels')->total())->toBe(0);

    config(['bookflix.search.semantic_min_similarity' => 0.60]);

    expect(semanticSearch('sad machine who feels')->total())->toBe(1);
});

test('a failing service falls back to keyword only with one warning', function (string $failure) {
    match ($failure) {
        'timeout' => Http::fake(fn () => throw new ConnectionException('Operation timed out after 300 milliseconds')),
        '503' => Http::fake(['ml.test/v1/embed' => Http::response(['detail' => 'model not loaded'], 503)]),
        'malformed' => Http::fake(['ml.test/v1/embed' => Http::response(['vectors' => 'nope'])]),
        'vectors shorter than dims' => fakeEmbedding([1.0, 0.0], dims: 384),
        'another model' => fakeEmbedding(unitVector([0 => 1.0]), 'intfloat/multilingual-e5-base@abc'),
        'another revision' => fakeEmbedding(unitVector([0 => 1.0]), E5.'@0000000'),
        'other dims' => fakeEmbedding(array_fill(0, 512, 0.044)),
    };
    Log::spy();

    $results = semanticSearch('berserk');

    expect($results->pluck('title')->all())->toBe(['Berserk']);
    Log::shouldHaveReceived('warning')->once();
})->with(['timeout', '503', 'malformed', 'vectors shorter than dims', 'another model', 'another revision', 'other dims']);

test('after a failure the leg is skipped for thirty seconds', function () {
    Http::fake(['ml.test/v1/embed' => Http::response([], 503)]);

    semanticSearch('sad machine who feels');
    semanticSearch('lonely robot');

    Http::assertSentCount(1);

    $this->travel(31)->seconds();
    semanticSearch('lonely robot');

    Http::assertSentCount(2);
});

test('the call has a 300 ms timeout', function () {
    $options = [];
    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(['model_version' => E5.'@'.E5_REVISION, 'dims' => 384, 'vectors' => [unitVector([0 => 1.0])]]);
    });

    semanticSearch('sad machine who feels');

    expect($options['timeout'])->toBe(0.3);
});

test('short or symbol only queries never call the service', function (string $query) {
    Http::fake();

    semanticSearch($query);
    app(SemanticLeg::class)->ids($query, new SearchFilters);

    Http::assertNothingSent();
})->with(['', 'ab', 'a+', '日本', '!!!', '#$%&*', '  -- ']);

test('query embeddings are cached per normalized query and active version', function () {
    fakeEmbedding(unitVector([0 => 1.0]));

    semanticSearch('Sad  machine who feels');
    $second = semanticSearch(' sad machine WHO feels ');

    expect($second->pluck('title')->all())->toBe(['A lonely robot learns to love']);
    Http::assertSentCount(1);

    $this->version->update(['status' => ModelStatus::Retired]);
    $this->version = embeddingVersion();
    embeddedStory('', unitVector([0 => 1.0]), media: $this->robot);

    semanticSearch('sad machine who feels');

    Http::assertSentCount(2);
});

test('no active embedding version makes no call', function () {
    Http::fake();
    $this->version->update(['status' => ModelStatus::Retired]);

    expect(semanticSearch('berserk')->pluck('title')->all())->toBe(['Berserk']);
    Http::assertNothingSent();
});

test('rows from a retired version are never used', function () {
    fakeEmbedding(unitVector([5 => 1.0]));
    embeddedStory('Clockwork Heart', unitVector([5 => 1.0]), embeddingVersion(ModelStatus::Retired));
    embeddedStory('Iron Saint', unitVector([5 => 1.0]), embeddingVersion(ModelStatus::Candidate));

    expect(semanticSearch('sad machine who feels')->total())->toBe(0);
});

test('a work the reader may not see never surfaces through its vector', function (string $hidden) {
    fakeEmbedding(unitVector([0 => 1.0]));

    match ($hidden) {
        'mature' => $this->robot->genres()->attach(Genre::factory()->mature()->create()),
        'adult rating' => $this->robot->update(['age_rating' => 'adult']),
        'soft deleted' => $this->robot->delete(),
        'merged' => $this->robot->update(['merged_into_id' => $this->berserk->id]),
    };

    expect(semanticSearch('sad machine who feels')->total())->toBe(0)
        ->and(semanticSearch('sad machine who feels', user: User::factory()->create())->total())->toBe(0);
})->with(['mature', 'adult rating', 'soft deleted', 'merged']);

test('a mature work surfaces for a reader who opted in', function () {
    fakeEmbedding(unitVector([0 => 1.0]));
    $this->robot->genres()->attach(Genre::factory()->mature()->create());

    expect(semanticSearch('sad machine who feels', user: User::factory()->showsMature()->create())->pluck('title')->all())
        ->toBe(['A lonely robot learns to love']);
});

test('filters narrow the semantic hits and the leg is capped', function () {
    fakeEmbedding(unitVector([0 => 1.0]));
    $manga = Media::factory()->manga()->create(['title' => 'Steel Tears']);
    embeddedStory('', unitVector([0 => 1.0, 1 => 0.1]), media: $manga);

    expect(semanticSearch('sad machine who feels', SearchFilters::fromArray(['mediaTypes' => ['manga']]))->pluck('title')->all())->toBe(['Steel Tears']);

    foreach (range(1, 35) as $i) {
        embeddedStory("Automaton {$i}", unitVector([0 => 1.0, 10 + $i => 0.05]));
    }

    $ids = app(SemanticLeg::class)->ids('sad machine who feels', new SearchFilters);
    $results = semanticSearch('sad machine who feels');

    expect($ids)->toHaveCount(30)
        ->and($ids[0])->toBe($this->robot->id)
        ->and($results->total())->toBe(30)
        ->and($results->hasMore)->toBeFalse();
});

test('an intent search embeds the semantic text once however often it relaxes', function () {
    $this->seed([MediaTypeSeeder::class, TaxonomySeeder::class, DeweySeeder::class]);
    fakeEmbedding(unitVector([0 => 1.0]));
    DB::enableQueryLog();

    $results = semanticSearch('sad manga about a machine', intent: new QueryIntent(mediaTypes: ['manga'], themes: ['revenge'], deweyCode: '940', semanticText: 'Sad about a machine'));

    expect($results->pluck('title')->all())->toBe(['A lonely robot learns to love'])
        ->and($results->appliedIntent)->toBe([])
        ->and(array_keys($results->suggestedIntent))->toBe(['mediaTypes', 'themes', 'deweyCode'])
        ->and(collect(DB::getQueryLog())->filter(fn (array $entry): bool => str_contains($entry['query'], '<=>')))->toHaveCount(1);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request['texts'] === ['sad about a machine']);
});

test('without an intent text the reader query is embedded', function () {
    fakeEmbedding(unitVector([0 => 1.0]));

    semanticSearch('lonely machine', intent: new QueryIntent);

    Http::assertSent(fn (Request $request) => $request['texts'] === ['lonely machine']);
});

test('a query naming prohibited content never reaches the semantic leg', function (?QueryIntent $intent) {
    Http::fake();
    DB::enableQueryLog();

    expect(semanticSearch('lolicon robot story', intent: $intent)->total())->toBe(0);

    Http::assertNothingSent();
    expect(collect(DB::getQueryLog())->filter(fn (array $entry): bool => str_contains($entry['query'], '<=>')))->toBeEmpty();
})->with([
    'no intent' => [null],
    'intent text' => [new QueryIntent(semanticText: 'robot story')],
]);
