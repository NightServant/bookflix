<?php

use App\Actions\Social\FileReport;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Collection;
use App\Models\Media;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

function reportError(User $reporter, Model $target, ReportReason $reason = ReportReason::Spam, ?string $details = null): ?array
{
    try {
        app(FileReport::class)->handle($reporter, $target, $reason, $details);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

test('a reader reports a published review', function () {
    $reporter = User::factory()->create();
    $review = Review::factory()->create();

    $report = app(FileReport::class)->handle($reporter, $review, ReportReason::Spoiler, '  Gives away the end ');

    expect(Report::query()->count())->toBe(1)
        ->and($report->fresh()->reportable_type)->toBe('review')
        ->and($report->fresh()->reason)->toBe(ReportReason::Spoiler)
        ->and($report->fresh()->details)->toBe('Gives away the end')
        ->and($report->fresh()->status)->toBe(ReportStatus::Open)
        ->and($report->fresh()->handled_by)->toBeNull()
        ->and($report->fresh()->reportable->is($review))->toBeTrue()
        // Reporting changes nothing about the content.
        ->and($review->fresh()->status->value)->toBe('published');
});

test('details are limited to 1000 characters', function () {
    $reporter = User::factory()->create();
    $review = Review::factory()->create();

    expect(reportError($reporter, $review, details: str_repeat('a', 1001)))->toHaveKey('details')
        ->and(reportError($reporter, $review, details: str_repeat('é', 1000)))->toBeNull();
});

test('only a signed-in active reader can report: an admin or deactivated account is refused', function () {
    $review = Review::factory()->create();

    expect(reportError(User::factory()->admin()->create(), $review))->toHaveKey('report')
        ->and(reportError(User::factory()->deactivated()->create(), $review))->toHaveKey('report')
        ->and(Report::query()->count())->toBe(0);
});

test('a reader cannot report their own review', function () {
    $owner = User::factory()->create();
    $review = Review::factory()->create(['user_id' => $owner->id]);

    expect(reportError($owner, $review))->toHaveKey('report')->and(Report::query()->count())->toBe(0);
});

test('a second open report of the same target returns the first', function () {
    $reporter = User::factory()->create();
    $review = Review::factory()->create();

    $first = app(FileReport::class)->handle($reporter, $review, ReportReason::Spam, null);
    $second = app(FileReport::class)->handle($reporter, $review, ReportReason::Abuse, 'again');

    expect($second->is($first))->toBeTrue()->and(Report::query()->count())->toBe(1)->and($first->fresh()->reason)->toBe(ReportReason::Spam);

    // Another reader may report it too, and once the first is settled this reader may report again.
    app(FileReport::class)->handle(User::factory()->create(), $review, ReportReason::Spam, null);
    $first->forceFill(['status' => ReportStatus::Dismissed])->save();
    app(FileReport::class)->handle($reporter, $review, ReportReason::Spam, null);

    expect(Report::query()->count())->toBe(3);
});

test('the database allows one open report per reporter and target', function () {
    $report = Report::factory()->create();

    expect(fn () => Report::factory()->create(['reporter_id' => $report->reporter_id, 'reportable_type' => $report->reportable_type, 'reportable_id' => $report->reportable_id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('classes outside the morph map and unrelated models are refused', function () {
    $reporter = User::factory()->create();

    expect(reportError($reporter, Media::factory()->create()))->toHaveKey('report')
        ->and(reportError($reporter, User::factory()->create()))->toHaveKey('report')
        ->and(reportError($reporter, new Review))->toHaveKey('report')
        ->and(Report::query()->count())->toBe(0);
});

test('content the reporter cannot see or that is not reportable is refused alike', function () {
    $reporter = User::factory()->create();
    $unreportable = [
        'hidden review' => Review::factory()->hidden()->create(),
        'deactivated author review' => Review::factory()->create(['user_id' => User::factory()->deactivated()]),
        'admin author review' => Review::factory()->create(['user_id' => User::factory()->admin()]),
        'review of a gated story' => Review::factory()->create(['media_id' => matureStory()->id]),
        'collection' => Collection::factory()->create(),
        'profile' => User::factory()->create()->profile,
    ];
    $deleted = Review::factory()->create();
    $deleted->delete();
    $unreportable['deleted review'] = $deleted;

    foreach ($unreportable as $target) {
        expect(reportError($reporter, $target))->toHaveKey('report');
    }

    expect(Report::query()->count())->toBe(0);
});

test('a review of a deleted or merged story is refused with a validation error, never a crash', function (Closure $retire) {
    $reporter = User::factory()->create();
    $review = Review::factory()->create();
    $retire($review->media);

    expect(reportError($reporter, $review->fresh()))->toHaveKey('report')->and(Report::query()->count())->toBe(0);
})->with([
    'deleted' => [fn (Media $media) => $media->delete()],
    'merged' => [fn (Media $media) => $media->forceFill(['merged_into_id' => Media::factory()->create()->id])->save()],
]);

test('a reader may file ten reports an hour', function () {
    $reporter = User::factory()->create();

    foreach (range(1, 10) as $i) {
        app(FileReport::class)->handle($reporter, Review::factory()->create(), ReportReason::Spam, null);
    }

    expect(reportError($reporter, Review::factory()->create()))->toHaveKey('report')
        ->and(Report::query()->count())->toBe(10)
        ->and(reportError(User::factory()->create(), Review::factory()->create()))->toBeNull();
});

test('a report whose target was deleted since still loads', function () {
    $review = Review::factory()->create();
    Report::factory()->of($review)->create();

    $review->delete();

    $reports = Report::query()->with('reportable')->get();

    expect($reports)->toHaveCount(1)->and($reports->pluck('reportable')->filter()->all())->toBe([]);
});

test('the morph map names review and no longer collection or profile', function () {
    expect(Relation::getMorphedModel('review'))->toBe(Review::class)
        ->and(Relation::getMorphedModel('collection'))->toBeNull()
        ->and(Relation::getMorphedModel('profile'))->toBeNull();
});
