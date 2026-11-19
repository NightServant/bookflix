<?php

use App\Actions\Social\DismissReport;
use App\Actions\Social\HideReportedContent;
use App\Actions\Social\RestoreContent;
use App\Enums\ReportStatus;
use App\Enums\ReviewState;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

dataset('moderation actions', [
    'hide' => [HideReportedContent::class],
    'dismiss' => [DismissReport::class],
    'restore' => [RestoreContent::class],
]);

test('every action refuses a reader, a deactivated reader and the reporter', function (string $action) {
    $report = Report::factory()->create();

    foreach ([User::factory()->create(), User::factory()->deactivated()->create(), $report->reporter] as $user) {
        expect(fn () => app($action)->handle($user, $report))->toThrow(AuthorizationException::class);
    }

    $report->refresh();

    expect($report->status)->toBe(ReportStatus::Open)
        ->and($report->handled_by)->toBeNull()
        ->and($report->reportable->status)->toBe(ReviewState::Published);
})->with('moderation actions');

test('hiding a review hides it, records the handler and removes it from the lists', function () {
    $admin = User::factory()->admin()->create();
    $review = Review::factory()->create();
    $report = Report::factory()->of($review)->create();

    app(HideReportedContent::class)->handle($admin, $report);

    expect($review->fresh()->status)->toBe(ReviewState::Hidden)
        ->and($report->fresh()->status)->toBe(ReportStatus::Actioned)
        ->and($report->fresh()->handled_by)->toBe($admin->id)
        ->and($report->fresh()->handled_at)->not->toBeNull()
        ->and(Review::query()->visibleOnStory($review->media, User::factory()->create())->count())->toBe(0)
        ->and(Review::query()->visibleOnStory($review->media, $review->user)->count())->toBe(1)
        ->and(Gate::forUser(User::factory()->create())->allows('view', $review->fresh()))->toBeFalse();
});

test('hiding actions every open report of the same target and no others', function () {
    $admin = User::factory()->admin()->create();
    $review = Review::factory()->create();
    $report = Report::factory()->of($review)->create();
    $sibling = Report::factory()->of($review)->create();
    $settled = Report::factory()->of($review)->dismissed()->create();
    $settledBy = $settled->handled_by;
    $elsewhere = Report::factory()->create();

    app(HideReportedContent::class)->handle($admin, $report);

    expect($sibling->fresh()->status)->toBe(ReportStatus::Actioned)
        ->and($sibling->fresh()->handled_by)->toBe($admin->id)
        ->and($sibling->fresh()->handled_at)->not->toBeNull()
        ->and($settled->fresh()->status)->toBe(ReportStatus::Dismissed)
        ->and($settled->fresh()->handled_by)->toBe($settledBy)
        ->and($elsewhere->fresh()->status)->toBe(ReportStatus::Open);
});

test('dismissing keeps the content and records the handler', function () {
    $admin = User::factory()->admin()->create();
    $review = Review::factory()->create();
    $report = Report::factory()->of($review)->create();
    $sibling = Report::factory()->of($review)->create();

    app(DismissReport::class)->handle($admin, $report);

    expect($report->fresh()->status)->toBe(ReportStatus::Dismissed)
        ->and($report->fresh()->handled_by)->toBe($admin->id)
        ->and($report->fresh()->handled_at)->not->toBeNull()
        ->and($review->fresh()->status)->toBe(ReviewState::Published)
        ->and($sibling->fresh()->status)->toBe(ReportStatus::Open);
});

test('restoring a review publishes it again', function () {
    $admin = User::factory()->admin()->create();
    $review = Review::factory()->hidden()->create();
    $report = Report::factory()->of($review)->create(['status' => ReportStatus::Actioned, 'handled_at' => now()]);

    app(RestoreContent::class)->handle($admin, $report);

    expect($review->fresh()->status)->toBe(ReviewState::Published)
        ->and($report->fresh()->handled_by)->toBe($admin->id);
});

test('a target deleted since cannot be hidden or restored, but the report can be dismissed', function () {
    $admin = User::factory()->admin()->create();
    $review = Review::factory()->create();
    $a = Report::factory()->of($review)->create();
    $review->delete();

    foreach ([HideReportedContent::class, RestoreContent::class] as $action) {
        expect(fn () => app($action)->handle($admin, $a->fresh()))->toThrow(ValidationException::class);
    }

    app(DismissReport::class)->handle($admin, $a->fresh());

    expect($a->fresh()->status)->toBe(ReportStatus::Dismissed);
});
