<?php

use App\Classification\ClassificationService;
use App\Classification\PrimaryClassificationExists;
use App\Classification\SecondaryClassificationLimit;
use App\Enums\ClassificationMethod;
use App\Enums\ClassificationType;
use App\Enums\ReviewStatus;
use App\Jobs\ClassifyMedia;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

beforeEach(fn () => $this->seed(DeweySeeder::class));

test('a second primary is rejected by the service and the database', function () {
    $media = Media::factory()->create();
    $svc = app(ClassificationService::class);
    $svc->assign($media, dewey('813'), ClassificationType::Primary, cand('813', .9));

    expect(fn () => $svc->assign($media, dewey('823'), ClassificationType::Primary, cand('823', .9)))->toThrow(PrimaryClassificationExists::class);
    expect(fn () => MediaDeweyClass::factory()->create(['media_id' => $media->id, 'dewey_class_id' => dewey('833')->id, 'classification_type' => 'primary', 'review_status' => 'pending']))->toThrow(QueryException::class);
});

test('a rejected primary does not block a new primary', function () {
    $media = Media::factory()->create();
    MediaDeweyClass::factory()->for($media)->create(['dewey_class_id' => dewey('813')->id, 'classification_type' => 'primary', 'review_status' => 'rejected']);

    app(ClassificationService::class)->assign($media, dewey('823'), ClassificationType::Primary, cand('823', .9));
    expect($media->deweyClasses()->count())->toBe(2);
});

test('manual assignments are accepted, automatic ones pending', function () {
    $media = Media::factory()->create();
    $svc = app(ClassificationService::class);

    expect($svc->assign($media, dewey('813'), ClassificationType::Primary, cand('813', 1.0, ClassificationMethod::Manual))->review_status)->toBe(ReviewStatus::Accepted)
        ->and($svc->assign($media, dewey('500'), ClassificationType::Secondary, cand('500', .8))->review_status)->toBe(ReviewStatus::Pending);
});

test('the same class cannot be both primary and secondary for one media', function () {
    $media = Media::factory()->create();
    $svc = app(ClassificationService::class);
    $svc->assign($media, dewey('813'), ClassificationType::Primary, cand('813', .9));
    $svc->assign($media, dewey('813'), ClassificationType::Secondary, cand('813', .9));
    expect($media->deweyClasses()->count())->toBe(1);
});

test('only accepted and confident pending classes are public, and rejected ones never', function () {
    $media = Media::factory()->create();
    $row = fn (string $code, float $confidence, ReviewStatus $status) => MediaDeweyClass::factory()->for($media)->create(['dewey_class_id' => dewey($code)->id, 'confidence' => $confidence, 'review_status' => $status]);
    $row('813', .60, ReviewStatus::Accepted);
    $row('823', .85, ReviewStatus::Pending);
    $row('833', .70, ReviewStatus::Pending);
    $row('843', .95, ReviewStatus::Rejected);

    expect(MediaDeweyClass::public()->with('deweyClass')->get()->map(fn (MediaDeweyClass $r) => $r->deweyClass->code)->sort()->values()->all())->toBe(['813', '823']);
});

test('a removed class stays as a rejected row, so classification never brings it back or shows it', function () {
    $media = Media::factory()->novel()->create(['country_of_origin' => 'US']);
    $svc = app(ClassificationService::class);
    $admin = User::factory()->admin()->create();
    $svc->apply($media, '813.54');
    $row = $media->deweyClasses()->where('dewey_class_id', dewey('813')->id)->sole();

    $svc->remove($row, $admin);
    $svc->apply($media, '813.54');
    (new ClassifyMedia($media->id))->handle($svc);

    expect($media->deweyClasses()->where('dewey_class_id', dewey('813')->id)->sole()->only(['id', 'review_status', 'reviewed_by']))
        ->toBe(['id' => $row->id, 'review_status' => ReviewStatus::Rejected, 'reviewed_by' => $admin->id])
        ->and($row->fresh()->reviewed_at)->not->toBeNull()
        ->and(MediaDeweyClass::public()->where('media_id', $media->id)->where('dewey_class_id', dewey('813')->id)->exists())->toBeFalse();
});

test('the service refuses a secondary beyond the limit, however it arrives', function () {
    $media = classified(Media::factory()->create(), '813');
    foreach (['500', '600', '700'] as $code) {
        classified($media, $code, type: 'secondary');
    }
    $rejected = MediaDeweyClass::factory()->for($media)->create(['dewey_class_id' => dewey('900')->id, 'classification_type' => 'secondary', 'review_status' => 'rejected']);
    $svc = app(ClassificationService::class);
    $admin = User::factory()->admin()->create();

    expect(fn () => $svc->assign($media, dewey('640'), ClassificationType::Secondary, cand('640', 1.0, ClassificationMethod::Manual), $admin))->toThrow(SecondaryClassificationLimit::class)
        ->and(fn () => $svc->accept($rejected, $admin))->toThrow(SecondaryClassificationLimit::class)
        ->and(fn () => $svc->replace($rejected, dewey('640'), $admin))->toThrow(SecondaryClassificationLimit::class);

    expect($media->deweyClasses()->count())->toBe(5)->and($rejected->fresh()->review_status)->toBe(ReviewStatus::Rejected);
});

test('swapping one live secondary for another stays within the limit', function () {
    $media = classified(Media::factory()->create(), '813');
    foreach (['500', '600', '700'] as $code) {
        classified($media, $code, type: 'secondary');
    }

    app(ClassificationService::class)->replace($media->deweyClasses()->where('dewey_class_id', dewey('500')->id)->sole(), dewey('640'), User::factory()->admin()->create());

    expect($media->deweyClasses()->where('classification_type', 'secondary')->where('review_status', '!=', 'rejected')->count())->toBe(3);
});

test('assigning with a reviewer records who decided and when', function () {
    $admin = User::factory()->admin()->create();

    $row = app(ClassificationService::class)->assign(Media::factory()->create(), dewey('813'), ClassificationType::Primary, cand('813', 1.0, ClassificationMethod::Manual), $admin);

    expect($row->fresh()->reviewed_by)->toBe($admin->id)->and($row->fresh()->reviewed_at)->not->toBeNull();
});

test('a concurrent run that wrote the same pair first is a no-op, not a failure', function () {
    $media = Media::factory()->create();
    $existing = MediaDeweyClass::factory()->for($media)->create(['dewey_class_id' => dewey('813')->id, 'classification_type' => 'primary', 'confidence' => .7]);

    // Hide the row from the service's first read, as a run that started before the other one committed would see it.
    $stale = true;
    MediaDeweyClass::addGlobalScope('stale', function (Builder $query) use (&$stale): void {
        if ($stale) {
            $query->whereRaw('false');
        }
    });
    MediaDeweyClass::creating(function () use (&$stale): void {
        $stale = false;
    });

    try {
        $row = app(ClassificationService::class)->assign($media, dewey('813'), ClassificationType::Primary, cand('813', .9));
    } finally {
        MediaDeweyClass::flushEventListeners();
        Model::clearBootedModels();
    }

    expect($row->id)->toBe($existing->id)->and($media->deweyClasses()->sole()->confidence)->toBe(.7);
});
