<?php

use App\Actions\Social\DismissReport;
use App\Actions\Social\HideReportedContent;
use App\Actions\Social\RestoreContent;
use App\Enums\ReportStatus;
use App\Enums\ReviewState;
use App\Enums\Visibility;
use App\Models\Collection;
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

test('hiding a collection makes it private and stamps it, and the policy refuses it', function () {
    $admin = User::factory()->admin()->create();
    $collection = Collection::factory()->public()->create();
    $report = Report::factory()->of($collection)->create();

    app(HideReportedContent::class)->handle($admin, $report);

    $collection->refresh();

    expect($collection->visibility)->toBe(Visibility::Private)
        ->and($collection->moderation_hidden_at)->not->toBeNull()
        ->and(Gate::forUser(User::factory()->create())->allows('view', $collection))->toBeFalse()
        ->and(Gate::forUser($collection->user)->allows('view', $collection))->toBeTrue()
        ->and(Collection::query()->viewableBy(User::factory()->create())->count())->toBe(0);
});

test('hiding a profile makes it private and stamps it, and view-profile refuses it', function () {
    $admin = User::factory()->admin()->create();
    $owner = moderatedReader('about_to_hide');
    $report = Report::factory()->of($owner->profile)->create();

    app(HideReportedContent::class)->handle($admin, $report);

    $profile = $owner->profile->fresh();

    expect($profile->profile_visibility)->toBe(Visibility::Private)
        ->and($profile->moderation_hidden_at)->not->toBeNull()
        ->and(Gate::forUser(User::factory()->create())->allows('view-profile', $owner->fresh()))->toBeFalse()
        ->and($report->fresh()->status)->toBe(ReportStatus::Actioned);
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

test('restoring a collection or profile clears the stamp and leaves it private', function () {
    $admin = User::factory()->admin()->create();
    $collection = Collection::factory()->create(['moderation_hidden_at' => now()]);
    $owner = moderatedReader('restore_me');
    $owner->profile->forceFill(['profile_visibility' => Visibility::Private, 'moderation_hidden_at' => now()])->save();

    app(RestoreContent::class)->handle($admin, Report::factory()->of($collection)->create());
    app(RestoreContent::class)->handle($admin, Report::factory()->of($owner->profile)->create());

    expect($collection->fresh()->moderation_hidden_at)->toBeNull()
        ->and($collection->fresh()->visibility)->toBe(Visibility::Private)
        ->and($owner->profile->fresh()->moderation_hidden_at)->toBeNull()
        ->and($owner->profile->fresh()->profile_visibility)->toBe(Visibility::Private);
});

test('a target deleted since cannot be hidden or restored, but the report can be dismissed', function () {
    $admin = User::factory()->admin()->create();
    $review = Review::factory()->create();
    $collection = Collection::factory()->public()->create();
    $a = Report::factory()->of($review)->create();
    $b = Report::factory()->of($collection)->create();
    $review->delete();
    $collection->delete();

    foreach ([HideReportedContent::class, RestoreContent::class] as $action) {
        expect(fn () => app($action)->handle($admin, $a->fresh()))->toThrow(ValidationException::class)
            ->and(fn () => app($action)->handle($admin, $b->fresh()))->toThrow(ValidationException::class);
    }

    app(DismissReport::class)->handle($admin, $a->fresh());

    expect($a->fresh()->status)->toBe(ReportStatus::Dismissed)->and($b->fresh()->status)->toBe(ReportStatus::Open);
});
