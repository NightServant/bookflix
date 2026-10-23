<?php

use App\Classification\ClassificationService;
use App\Classification\PrimaryClassificationExists;
use App\Enums\ClassificationMethod;
use App\Enums\ClassificationType;
use App\Enums\ReviewStatus;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use Database\Seeders\DeweySeeder;
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

    expect(MediaDeweyClass::public()->get()->map(fn (MediaDeweyClass $r) => $r->deweyClass->code)->sort()->values()->all())->toBe(['813', '823'])
        ->and(Media::publicDewey()->count())->toBe(1)
        ->and(Media::publicDewey(dewey('810'))->count())->toBe(1)
        ->and(Media::publicDewey(dewey('830'))->count())->toBe(0);
});
