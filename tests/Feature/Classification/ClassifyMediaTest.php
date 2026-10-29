<?php

use App\Classification\ClassificationService;
use App\Classification\DeweyCounts;
use App\Enums\ClassificationMethod;
use App\Enums\ClassificationType;
use App\Enums\ReviewStatus;
use App\Jobs\ClassifyMedia;
use App\Models\Genre;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use App\Models\MediaExternalId;
use App\Models\MediaType;
use App\Models\Tag;
use Database\Seeders\DeweySeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->seed(DeweySeeder::class));

test('dune gets primary 813 from the external code at 0.95', function () {
    $media = Media::factory()->novel()->create(['title' => 'Dune', 'language' => 'en', 'country_of_origin' => 'US']);
    $c = app(ClassificationService::class)->classify($media, '813.54');

    expect($c->first()->code)->toBe('813')->and($c->first()->method)->toBe(ClassificationMethod::External)->and($c->first()->confidence)->toBe(.95);
});

test('the martian gets 813 primary with 500 and 600 secondaries from subjects', function () {
    $media = Media::factory()->novel()->create(['title' => 'The Martian', 'language' => 'en', 'country_of_origin' => 'US']);
    $media->tags()->attach([Tag::factory()->create(['name' => 'Science'])->id, Tag::factory()->create(['name' => 'Engineering'])->id]);

    expect(app(ClassificationService::class)->classify($media, '813.6')->pluck('code')->all())->toBe(['813', '500', '600']);
});

test('subject keywords also match single words of the description', function () {
    $media = Media::factory()->novel()->create(['country_of_origin' => 'US', 'description' => 'A short history of cooking, told by a chef.']);

    expect(app(ClassificationService::class)->classify($media)->pluck('code')->all())->toBe(['813', '900', '640']);
});

test('prose falls back from country to language to class 800', function (?string $country, ?string $language, string $code, float $confidence) {
    $media = Media::factory()->novel()->create(['country_of_origin' => $country, 'language' => $language, 'description' => null]);
    $first = app(ClassificationService::class)->classify($media)->first();

    expect($first->code)->toBe($code)->and($first->confidence)->toBe($confidence);
})->with([
    ['GB', 'en', '823', .75], ['BR', 'fr', '843', .75], [null, 'ja', '895.6', .75], [null, null, '800', .70], ['BR', 'pt', '800', .70],
]);

test('a fiction genre raises the prose confidence', function () {
    $media = Media::factory()->novel()->create(['country_of_origin' => 'FR']);
    $media->genres()->attach(Genre::factory()->create(['slug' => 'fantasy']));

    expect(app(ClassificationService::class)->classify($media)->first()->confidence)->toBe(.85);
});

test('a berserk style manga gets 741.52 as primary', function () {
    $media = Media::factory()->manga()->create(['country_of_origin' => 'JP', 'language' => 'ja']);
    expect(app(ClassificationService::class)->classify($media)->first()->code)->toBe('741.52');
});

test('a literary graphic novel also gets a secondary literature class', function () {
    $media = Media::factory()->state(['country_of_origin' => 'US'])->graphicNovel()->create();
    $media->genres()->attach(Genre::factory()->create(['slug' => 'literary-fiction']));
    expect(app(ClassificationService::class)->classify($media)->pluck('code')->all())->toBe(['741.55', '813']);
});

test('a visual novel gets the literature class at 0.70', function () {
    $media = Media::factory()->state(['country_of_origin' => 'JP', 'language' => 'ja'])->create(['media_type_id' => MediaType::factory()->create(['family' => 'interactive'])->id]);
    $first = app(ClassificationService::class)->classify($media)->first();

    expect($first->code)->toBe('895.6')->and($first->confidence)->toBe(.70);
});

test('garbage external codes never throw and never add a class', function (string $code) {
    $media = Media::factory()->novel()->create(['country_of_origin' => 'US']);
    $codes = fn () => app(ClassificationService::class)->classify($media, $code)->map(fn ($c) => $c->method)->all();

    expect($codes)->not->toThrow(Throwable::class)
        ->and($codes())->toBe([ClassificationMethod::Automatic]);
})->with(['999.99', '', 'abc', '813.', '8 13', '-813']);

test('a sparse record classifies without error', function () {
    $media = Media::factory()->create(['country_of_origin' => null, 'language' => null, 'description' => null]);
    expect(fn () => app(ClassificationService::class)->classify($media))->not->toThrow(Throwable::class);
    expect(fn () => app(ClassificationService::class)->apply($media))->not->toThrow(Throwable::class);
    expect(app(ClassificationService::class)->classify($media)->pluck('code')->all())->toBe(['800']);
});

test('classify only suggests and stores nothing', function () {
    $media = Media::factory()->novel()->create(['country_of_origin' => 'US']);
    app(ClassificationService::class)->classify($media, '813.54');

    expect(MediaDeweyClass::count())->toBe(0);
});

test('re-running classification never overrides a manual decision', function () {
    $media = Media::factory()->novel()->create(['country_of_origin' => 'US']);
    app(ClassificationService::class)->assign($media, dewey('823'), ClassificationType::Primary, cand('823', 1.0, ClassificationMethod::Manual));

    app(ClassificationService::class)->apply($media);

    expect($media->deweyClasses()->where('classification_type', 'primary')->pluck('dewey_class_id')->all())->toBe([dewey('823')->id]);
});

test('an automatic assignment never changes a manual, accepted or rejected row', function (ClassificationMethod $method, ReviewStatus $status) {
    $media = Media::factory()->create();
    $row = MediaDeweyClass::factory()->for($media)->create([
        'dewey_class_id' => dewey('813')->id,
        'classification_type' => 'primary',
        'confidence' => 1.0,
        'source' => 'admin',
        'classification_method' => $method,
        'review_status' => $status,
    ]);

    foreach ([ClassificationMethod::Automatic, ClassificationMethod::External] as $automatic) {
        app(ClassificationService::class)->assign($media, dewey('813'), ClassificationType::Secondary, cand('813', .6, $automatic));
    }

    expect($row->fresh()->only(['classification_type', 'confidence', 'source', 'classification_method', 'review_status']))
        ->toBe(['classification_type' => ClassificationType::Primary, 'confidence' => 1.0, 'source' => 'admin', 'classification_method' => $method, 'review_status' => $status]);
})->with([
    'manual' => [ClassificationMethod::Manual, ReviewStatus::Accepted],
    'manual and rejected' => [ClassificationMethod::Manual, ReviewStatus::Rejected],
    'accepted' => [ClassificationMethod::Automatic, ReviewStatus::Accepted],
    'rejected' => [ClassificationMethod::Automatic, ReviewStatus::Rejected],
]);

test('a manual assignment can still change a rejected row', function () {
    $media = Media::factory()->create();
    MediaDeweyClass::factory()->for($media)->create(['dewey_class_id' => dewey('813')->id, 'classification_type' => 'primary', 'review_status' => 'rejected']);

    $row = app(ClassificationService::class)->assign($media, dewey('813'), ClassificationType::Primary, cand('813', 1.0, ClassificationMethod::Manual));

    expect($row->review_status)->toBe(ReviewStatus::Accepted)->and($row->classification_method)->toBe(ClassificationMethod::Manual);
});

test('an automatic assignment still refreshes a pending automatic row', function () {
    $media = Media::factory()->create();
    $svc = app(ClassificationService::class);
    $svc->assign($media, dewey('813'), ClassificationType::Primary, cand('813', .7));
    $svc->assign($media, dewey('813'), ClassificationType::Primary, cand('813', .9));

    expect($media->deweyClasses()->sole()->confidence)->toBe(.9);
});

test('re-running classification does not resurrect a rejected class', function () {
    $media = Media::factory()->novel()->create(['country_of_origin' => 'US']);
    MediaDeweyClass::factory()->for($media)->create(['dewey_class_id' => dewey('813')->id, 'classification_type' => 'primary', 'review_status' => 'rejected']);

    app(ClassificationService::class)->apply($media);

    expect($media->deweyClasses()->where('dewey_class_id', dewey('813')->id)->first()->review_status)->toBe(ReviewStatus::Rejected);
});

test('applying twice stores the same rows and keeps within the secondary limit', function () {
    $media = Media::factory()->novel()->create(['country_of_origin' => 'US', 'description' => 'science engineering history cooking philosophy']);
    $svc = app(ClassificationService::class);

    $svc->apply($media, '813.54');
    $first = $media->deweyClasses()->orderBy('id')->pluck('dewey_class_id')->all();
    $svc->apply($media, '813.54');

    expect($media->deweyClasses()->orderBy('id')->pluck('dewey_class_id')->all())->toBe($first)
        ->and($media->deweyClasses()->where('classification_type', 'primary')->count())->toBe(1)
        ->and($media->deweyClasses()->where('classification_type', 'secondary')->count())->toBe(3);
});

test('applying never adds a primary beside an existing live primary', function () {
    $media = Media::factory()->novel()->create(['country_of_origin' => 'US']);
    MediaDeweyClass::factory()->for($media)->create(['dewey_class_id' => dewey('900')->id, 'classification_type' => 'primary']);

    app(ClassificationService::class)->apply($media, '813.54');

    expect($media->deweyClasses()->where('classification_type', 'primary')->pluck('dewey_class_id')->all())->toBe([dewey('900')->id]);
});

test('ingestion dispatches classify media', function () {
    Bus::fake([ClassifyMedia::class]);
    importFixture('anilist', 'manga-berserk');
    Bus::assertDispatched(ClassifyMedia::class, fn (ClassifyMedia $job) => $job->mediaId === Media::sole()->id && $job->queue === 'ingestion');
});

test('classify media persists pending rows with confidence and source', function () {
    $media = Media::factory()->manga()->create(['country_of_origin' => 'JP']);
    (new ClassifyMedia($media->id))->handle(app(ClassificationService::class));
    $row = $media->deweyClasses()->first();
    expect($row->review_status)->toBe(ReviewStatus::Pending)->and($row->source)->toBe('rules')->and((float) $row->confidence)->toBe(.8);
});

test('classify media skips a media that no longer exists', function () {
    expect(fn () => (new ClassifyMedia(999999))->handle(app(ClassificationService::class)))->not->toThrow(Throwable::class);
});

test('classification reads the dewey code of every external id of the media', function () {
    $media = Media::factory()->novel()->create(['country_of_origin' => 'US']);
    MediaExternalId::factory()->for($media)->create(['dewey_code' => '823.914']);
    MediaExternalId::factory()->for($media)->create(['dewey_code' => null]);
    MediaExternalId::factory()->for($media)->create(['dewey_code' => '843']);
    $svc = app(ClassificationService::class);

    $suggested = $svc->classify($media);
    $svc->apply($media);

    expect($suggested->take(2)->map(fn ($c) => [$c->code, $c->method])->all())->toBe([['823', ClassificationMethod::External], ['843', ClassificationMethod::External]])
        ->and($media->primaryDewey->deweyClass->code)->toBe('823')
        ->and($media->deweyClasses()->where('dewey_class_id', dewey('843')->id)->value('classification_type'))->toBe(ClassificationType::Secondary);
});

test('classify media is queued once per media while it waits', function () {
    config(['queue.default' => 'database']);

    ClassifyMedia::dispatch(7);
    ClassifyMedia::dispatch(7);
    ClassifyMedia::dispatch(8);

    expect(DB::table('jobs')->count())->toBe(2);
});

test('a classify run flushes the cached counts once however many rows it writes', function () {
    $counts = Mockery::spy(DeweyCounts::class);
    app()->instance(DeweyCounts::class, $counts);
    $media = Media::factory()->novel()->create(['country_of_origin' => 'US', 'description' => 'science engineering history cooking philosophy']);

    (new ClassifyMedia($media->id))->handle(app(ClassificationService::class));

    expect($media->deweyClasses()->count())->toBeGreaterThan(2);
    $counts->shouldHaveReceived('flush')->once();
});

test('a classify run that writes nothing leaves the cached counts alone', function () {
    $media = Media::factory()->novel()->create(['country_of_origin' => 'US']);
    (new ClassifyMedia($media->id))->handle(app(ClassificationService::class));
    $counts = Mockery::spy(DeweyCounts::class);
    app()->instance(DeweyCounts::class, $counts);

    (new ClassifyMedia($media->id))->handle(app(ClassificationService::class));

    $counts->shouldNotHaveReceived('flush');
});
