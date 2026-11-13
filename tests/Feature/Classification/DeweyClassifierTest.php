<?php

use App\Classification\ClassificationService;
use App\Classification\Dewey\DeweyClassifier;
use App\Enums\ClassificationMethod;
use App\Enums\ClassificationType;
use App\Enums\ModelKind;
use App\Enums\ReviewStatus;
use App\Jobs\ClassifyMedia;
use App\Models\Genre;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use App\Models\ModelVersion;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DeweySeeder::class);
    config(['services.ml.url' => 'http://ml.test', 'services.ml.token' => str_repeat('s', 32)]);
});

/**
 * The active classifier answering these candidates (code => raw confidence) for every work.
 *
 * @param  array<string, float>  $candidates
 */
function classifierSays(array $candidates): ModelVersion
{
    $version = ModelVersion::factory()->active()->create(['name' => 'dewey-classifier', 'kind' => ModelKind::DeweyClassifier, 'version' => '20261112T031500123456Z']);
    Http::fake(['ml.test/v1/classify/dewey' => Http::response([
        'candidates' => collect($candidates)->map(fn (float $confidence, string $code) => ['code' => $code, 'confidence' => $confidence])->values()->all(),
        'model_version' => $version->version,
    ])]);

    return $version;
}

/**
 * A comic with no country: the rules give it 741.59 at 0.80.
 */
function plainComic(string $title = 'A Comic'): Media
{
    return Media::factory()->ofType('manga')->create(['title' => $title, 'country_of_origin' => null, 'language' => null, 'description' => null]);
}

/**
 * A work the rules have nothing for: prose with no genres.
 */
function unruled(string $title = 'Untagged'): Media
{
    return Media::factory()->novel()->create(['title' => $title, 'country_of_origin' => null, 'language' => null, 'description' => null]);
}

test('a 0.95 raw probability is stored as 0.79, pending and not public; the review queue shows it', function () {
    $version = classifierSays(['520' => 0.95]);
    $media = unruled('Star Atlas');

    (new ClassifyMedia($media->id))->handle(app(ClassificationService::class));

    $row = MediaDeweyClass::where('media_id', $media->id)->sole();
    expect($row->confidence)->toBe(0.79)
        ->and($row->review_status)->toBe(ReviewStatus::Pending)
        ->and($row->classification_type)->toBe(ClassificationType::Primary)
        ->and($row->classification_method)->toBe(ClassificationMethod::Automatic)
        ->and($row->source)->toBe('dewey-classifier')
        ->and($row->model_version_id)->toBe($version->id)
        ->and(MediaDeweyClass::public()->count())->toBe(0);

    $this->actingAs(User::factory()->create())->get(route('explore.show', '500'))->assertDontSee('Star Atlas');
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test('pages::admin.classifications.index')
        ->assertSee('Star Atlas')->assertSee('79% confidence')->assertSee('Model '.$version->version);
});

test('nothing from the classifier alone is public, however confident', function () {
    classifierSays(['520' => 1.0, '530' => 0.99, '540' => 0.98]);
    $media = unruled();

    app(ClassificationService::class)->apply($media);

    expect(MediaDeweyClass::where('media_id', $media->id)->count())->toBe(3)
        ->and(MediaDeweyClass::where('media_id', $media->id)->max('confidence'))->toBe('0.790')
        ->and(MediaDeweyClass::public()->count())->toBe(0);
});

test('a reader-visible listing is unchanged by a pending classifier candidate', function () {
    $shown = Media::factory()->create(['title' => 'Already Shelved']);
    classified($shown, '520');
    $reader = User::factory()->create();
    $before = $this->actingAs($reader)->get(route('explore.show', '520'))->assertSee('Already Shelved')->getContent();
    classifierSays(['520' => 0.99]);

    app(ClassificationService::class)->apply(unruled('Waiting For Review'));

    $after = $this->actingAs($reader)->get(route('explore.show', '520'))->getContent();
    expect($after)->not->toContain('Waiting For Review')
        ->and(substr_count($after, 'Already Shelved'))->toBe(substr_count($before, 'Already Shelved'));
});

test('classifier output is passed through DeweyValidator: one primary and at most three secondaries', function () {
    classifierSays(['520' => 0.6, '530' => 0.58, '540' => 0.57, '550' => 0.56, '560' => 0.55]);
    $media = unruled();

    $candidates = app(ClassificationService::class)->classify($media);
    app(ClassificationService::class)->apply($media);

    expect($candidates->pluck('code')->all())->toBe(['520', '530', '540', '550'])
        ->and(MediaDeweyClass::where('media_id', $media->id)->where('classification_type', 'primary')->count())->toBe(1)
        ->and(MediaDeweyClass::where('media_id', $media->id)->where('classification_type', 'secondary')->count())->toBe(3);
});

test('weak classifier candidates are dropped like weak rules', function () {
    classifierSays(['520' => 0.49, '530' => 0.3]);

    expect(app(ClassificationService::class)->classify(unruled()))->toBeEmpty();
});

test('codes missing from the seeded hierarchy are dropped', function () {
    classifierSays(['999.99' => 0.7, '520' => 0.6]);

    expect(collect(app(DeweyClassifier::class)->predict(unruled()))->pluck('code')->all())->toBe(['520']);
});

test('a classifier candidate never outranks a rule or an external primary', function () {
    classifierSays(['520' => 0.99]);
    $novel = Media::factory()->ofType('visual-novel')->create(['country_of_origin' => null, 'language' => null, 'description' => null]);

    expect(app(ClassificationService::class)->classify($novel)->pluck('code')->all())->toBe(['800', '520'])
        ->and(app(ClassificationService::class)->classify(plainComic(), '813')->pluck('code')->all())->toBe(['813', '741.59', '520']);
});

test('the #28 rule holds for the classifier: no literature class for prose with no genres or nonfiction', function () {
    classifierSays(['813' => 0.9, '895.6' => 0.8, '520' => 0.6]);
    $nonfiction = Media::factory()->ofType('nonfiction')->create(['description' => null]);
    $fiction = Media::factory()->novel()->create(['description' => null, 'country_of_origin' => null, 'language' => null]);
    $fiction->genres()->attach(Genre::factory()->create(['slug' => 'fantasy']));

    expect(collect(app(DeweyClassifier::class)->predict(unruled()))->pluck('code')->all())->toBe(['520'])
        ->and(collect(app(DeweyClassifier::class)->predict($nonfiction))->pluck('code')->all())->toBe(['520'])
        ->and(collect(app(DeweyClassifier::class)->predict($fiction))->pluck('code')->all())->toBe(['813', '895.6', '520']);
});

test('it never overwrites a manual or accepted decision and never resurrects a rejected pair', function () {
    classifierSays(['520' => 0.79, '530' => 0.7, '540' => 0.6]);
    $media = unruled();
    classified($media, '520', status: 'rejected', confidence: 0.5);
    classified($media, '530', type: 'secondary', status: 'accepted', confidence: 0.9);
    MediaDeweyClass::factory()->for($media)->create(['dewey_class_id' => dewey('540')->id, 'classification_type' => ClassificationType::Secondary, 'classification_method' => ClassificationMethod::Manual, 'review_status' => ReviewStatus::Accepted, 'confidence' => 1.0, 'source' => 'admin']);
    $before = MediaDeweyClass::where('media_id', $media->id)->orderBy('id')->get()->map->only(['dewey_class_id', 'review_status', 'confidence', 'source', 'model_version_id'])->all();

    app(ClassificationService::class)->apply($media);

    expect(MediaDeweyClass::where('media_id', $media->id)->orderBy('id')->get()->map->only(['dewey_class_id', 'review_status', 'confidence', 'source', 'model_version_id'])->all())->toBe($before);
});

test('ML down, a timeout, a 503 or a malformed answer means rules only, no exception, one warning', function (Closure $answer) {
    ModelVersion::factory()->active()->create(['name' => 'dewey-classifier', 'kind' => ModelKind::DeweyClassifier, 'version' => '20261112T031500123456Z']);
    Http::fake(['ml.test/v1/classify/dewey' => $answer]);
    Log::spy();
    $comic = plainComic();

    expect(app(ClassificationService::class)->classify($comic)->pluck('code')->all())->toBe(['741.59']);
    Log::shouldHaveReceived('warning')->once();
})->with([
    'down' => fn () => fn () => throw new ConnectionException('Connection refused'),
    'timeout' => fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    '503' => fn () => fn () => Http::response(['detail' => 'No Dewey classifier version is active.'], 503),
    'malformed' => fn () => fn () => Http::response(['candidates' => 'nope', 'model_version' => '20261112T031500123456Z']),
]);

test('with no ML service configured the rules run alone and nothing is logged', function () {
    config(['services.ml.token' => '']);
    Http::fake();
    Log::spy();

    expect(app(ClassificationService::class)->classify(plainComic())->pluck('code')->all())->toBe(['741.59']);
    Http::assertNothingSent();
    Log::shouldNotHaveReceived('warning');
});

test('candidates carry the model version id', function () {
    $version = classifierSays(['520' => 0.6]);

    expect(app(DeweyClassifier::class)->predict(unruled())[0]->modelVersionId)->toBe($version->id);
});

test('a web request never reaches the classifier: classification there is rules only', function () {
    classifierSays(['520' => 0.7]);
    $comic = plainComic();
    $app = app();
    $console = (new ReflectionProperty($app, 'isRunningInConsole'));
    $console->setValue($app, false);

    try {
        $codes = app(ClassificationService::class)->classify($comic)->pluck('code')->all();
        (new ClassifyMedia($comic->id))->handle(app(ClassificationService::class));
    } finally {
        $console->setValue($app, true);
    }

    expect($codes)->toBe(['741.59'])
        ->and(MediaDeweyClass::where('media_id', $comic->id)->pluck('source')->all())->toBe(['rules']);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'classify'));
});

test('with no active classifier version the service is not asked', function () {
    Http::fake();
    Log::spy();

    expect(app(ClassificationService::class)->classify(plainComic())->pluck('code')->all())->toBe(['741.59']);
    Http::assertNothingSent();
    Log::shouldNotHaveReceived('warning');
});
