<?php

use App\Actions\Social\FileReport;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\Visibility;
use App\Models\Collection;
use App\Models\Media;
use App\Models\Profile;
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

function moderatedReader(string $handle = 'reported_reader'): User
{
    $user = User::factory()->create();
    $user->profile()->updateOrCreate([], ['handle' => $handle, 'profile_visibility' => Visibility::Public]);

    return $user->fresh();
}

test('a reader reports a published review, a public collection and a public profile', function () {
    $reporter = User::factory()->create();
    $owner = moderatedReader();
    $review = Review::factory()->create();
    $collection = Collection::factory()->public()->create();

    $one = app(FileReport::class)->handle($reporter, $review, ReportReason::Spoiler, '  Gives away the end ');
    $two = app(FileReport::class)->handle($reporter, $collection, ReportReason::Abuse, null);
    $three = app(FileReport::class)->handle($reporter, $owner->profile, ReportReason::Other, '');

    expect(Report::query()->count())->toBe(3)
        ->and($one->fresh()->reportable_type)->toBe('review')
        ->and($one->fresh()->reason)->toBe(ReportReason::Spoiler)
        ->and($one->fresh()->details)->toBe('Gives away the end')
        ->and($one->fresh()->status)->toBe(ReportStatus::Open)
        ->and($one->fresh()->handled_by)->toBeNull()
        ->and($two->fresh()->reportable_type)->toBe('collection')
        ->and($three->fresh()->reportable_type)->toBe('profile')
        ->and($three->fresh()->details)->toBeNull()
        ->and($three->fresh()->reportable->is($owner->profile))->toBeTrue()
        // Reporting changes nothing about the content.
        ->and($review->fresh()->status->value)->toBe('published')
        ->and($collection->fresh()->visibility)->toBe(Visibility::Public)
        ->and($owner->profile->fresh()->moderation_hidden_at)->toBeNull();
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

test('a reader cannot report their own review, collection or profile', function () {
    $owner = moderatedReader('self_reporter');
    $review = Review::factory()->create(['user_id' => $owner->id]);
    $collection = Collection::factory()->public()->create(['user_id' => $owner->id]);

    expect(reportError($owner, $review))->toHaveKey('report')
        ->and(reportError($owner, $collection))->toHaveKey('report')
        ->and(reportError($owner, $owner->profile))->toHaveKey('report')
        ->and(Report::query()->count())->toBe(0);
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
    $hiddenProfile = moderatedReader('hidden_one')->profile;
    $hiddenProfile->forceFill(['moderation_hidden_at' => now()])->save();
    $admin = User::factory()->admin()->create();
    $admin->profile()->update(['profile_visibility' => Visibility::Public]);
    $adminProfile = $admin->profile->fresh();
    $unreportable = [
        'hidden review' => Review::factory()->hidden()->create(),
        'deactivated author review' => Review::factory()->create(['user_id' => User::factory()->deactivated()]),
        'admin author review' => Review::factory()->create(['user_id' => User::factory()->admin()]),
        'review of a gated story' => Review::factory()->create(['media_id' => matureStory()->id]),
        'private collection' => Collection::factory()->create(),
        'editorial collection' => Collection::factory()->public()->editorial()->create(),
        'moderator-hidden collection' => Collection::factory()->public()->create(['moderation_hidden_at' => now()]),
        'collection of a deactivated reader' => Collection::factory()->public()->create(['user_id' => User::factory()->deactivated()]),
        'private profile' => User::factory()->create()->profile,
        'hidden profile' => $hiddenProfile,
        'admin profile' => $adminProfile,
    ];
    $deleted = Review::factory()->create();
    $deleted->delete();
    $unreportable['deleted review'] = $deleted;

    foreach ($unreportable as $target) {
        $target = $target instanceof User ? $target->profile : $target;
        expect(reportError($reporter, $target))->toHaveKey('report');
    }

    expect(Report::query()->count())->toBe(0);
});

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
    $collection = Collection::factory()->public()->create();
    $a = Report::factory()->of($review)->create();
    $b = Report::factory()->of($collection)->create();

    $review->delete();
    $collection->delete();

    $reports = Report::query()->with('reportable')->get();

    expect($reports)->toHaveCount(2)->and($reports->pluck('reportable')->filter()->all())->toBe([]);
});

test('the morph map names review, collection and profile', function () {
    expect(Relation::getMorphedModel('review'))->toBe(Review::class)
        ->and(Relation::getMorphedModel('collection'))->toBe(Collection::class)
        ->and(Relation::getMorphedModel('profile'))->toBe(Profile::class);
});
