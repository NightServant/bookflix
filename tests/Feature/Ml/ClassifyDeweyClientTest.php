<?php

use App\Classification\ClassificationCandidate;
use App\Enums\ClassificationMethod;
use App\Enums\ModelKind;
use App\Ml\MlClient;
use App\Ml\MlServiceUnavailable;
use App\Models\Genre;
use App\Models\Media;
use App\Models\MediaExternalId;
use App\Models\ModelVersion;
use App\Models\Tag;
use App\Models\Theme;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['services.ml.url' => 'http://ml.test', 'services.ml.token' => str_repeat('s', 32)]));

/**
 * The active classifier version the shared fixture names.
 */
function fixtureClassifier(): ModelVersion
{
    return ModelVersion::factory()->active()->create(['name' => 'dewey-classifier', 'kind' => ModelKind::DeweyClassifier, 'version' => contract('classify-dewey.json')['model_version']]);
}

test('classifyDewey posts the work and maps the contract to candidates of the active version', function () {
    $version = fixtureClassifier();
    $media = Media::factory()->create(['title' => 'Berserk', 'description' => 'A lone mercenary.']);
    $media->genres()->attach(Genre::factory()->create(['name' => 'Dark Fantasy']));
    $media->themes()->attach(Theme::factory()->create(['name' => 'Revenge']));
    $media->tags()->attach(Tag::factory()->create(['name' => 'Mercenaries']));
    MediaExternalId::factory()->for($media)->create(['dewey_code' => '741.5952']);
    Http::fake(['ml.test/v1/classify/dewey' => Http::response(contract('classify-dewey.json'))]);

    $candidates = app(MlClient::class)->classifyDewey($media);

    expect($candidates)->toHaveCount(3)->each->toBeInstanceOf(ClassificationCandidate::class)
        ->and($candidates[0]->code)->toBe('741.52')
        ->and($candidates[0]->confidence)->toBe(0.6213)
        ->and($candidates[0]->method)->toBe(ClassificationMethod::Automatic)
        ->and($candidates[0]->source)->toBe('dewey-classifier')
        ->and($candidates[0]->modelVersionId)->toBe($version->id);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['media_id'] === $media->id
        && $request['text'] === "Berserk\nA lone mercenary."
        && $request['genres'] === ['Dark Fantasy'] && $request['themes'] === ['Revenge'] && $request['subjects'] === ['Mercenaries']
        && $request['external_code'] === '741.5952'
        && array_keys($request->data()) === ['media_id', 'text', 'genres', 'themes', 'subjects', 'external_code']);
});

test('classifyDewey refuses an answer from a version that is not the active classifier', function () {
    ModelVersion::factory()->create(['name' => 'dewey-classifier', 'kind' => ModelKind::DeweyClassifier, 'version' => contract('classify-dewey.json')['model_version']]);
    Http::fake(['ml.test/v1/classify/dewey' => Http::response(contract('classify-dewey.json'))]);

    app(MlClient::class)->classifyDewey(Media::factory()->create());
})->throws(MlServiceUnavailable::class);

test('classifyDewey turns an error or a malformed answer into MlServiceUnavailable', function (mixed $response) {
    fixtureClassifier();
    Http::fake(['ml.test/v1/classify/dewey' => $response]);

    app(MlClient::class)->classifyDewey(Media::factory()->create());
})->throws(MlServiceUnavailable::class)->with([
    '503' => fn () => Http::response(['detail' => 'No Dewey classifier version is active.'], 503),
    'no candidates key' => fn () => Http::response(['model_version' => contract('classify-dewey.json')['model_version']]),
    'a code not a string' => fn () => Http::response([...contract('classify-dewey.json'), 'candidates' => [['code' => 813, 'confidence' => 0.5]]]),
    'a confidence above 1' => fn () => Http::response([...contract('classify-dewey.json'), 'candidates' => [['code' => '813', 'confidence' => 1.5]]]),
    'candidates not a list' => fn () => Http::response([...contract('classify-dewey.json'), 'candidates' => ['code' => '813']]),
    'no model version' => fn () => Http::response(['candidates' => []]),
]);

test('a timeout is MlServiceUnavailable', function () {
    fixtureClassifier();
    Http::fake(fn () => throw new ConnectionException('timed out'));

    app(MlClient::class)->classifyDewey(Media::factory()->create());
})->throws(MlServiceUnavailable::class);
