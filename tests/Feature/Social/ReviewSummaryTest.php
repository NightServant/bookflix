<?php

use App\Actions\Social\DeleteReview;
use App\Actions\Social\HideReportedContent;
use App\Actions\Social\RestoreContent;
use App\Actions\Social\SaveReview;
use App\Jobs\SummarizeReviews;
use App\Models\Media;
use App\Models\Report;
use App\Models\Review;
use App\Models\ReviewSummary;
use App\Models\User;
use App\Social\ReviewSummaries;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.ml.url' => 'http://ml.test', 'services.ml.token' => str_repeat('s', 32), 'services.ml.timeout' => 5.0, 'services.ml.connect_timeout' => 1.0]);
    Queue::fake();
});

function reviewsOf(Media $media, int $count, array $state = []): void
{
    foreach (range(1, $count) as $i) {
        Review::factory()->create(['media_id' => $media->id, 'body' => "Plain review {$i}.", 'created_at' => now()->subMinutes(100 - $i)] + $state);
    }
}

function summarizeFake(?string $summary = 'Readers liked it.'): void
{
    Http::fake(['ml.test/v1/summarize' => Http::response(['summary' => $summary, 'method' => 'lsa'])]);
}

function runSummary(Media $media): void
{
    app()->call([new SummarizeReviews($media->id), 'handle']);
}

function storedSummary(Media $media, string $summary = 'Old summary.'): ReviewSummary
{
    return ReviewSummary::query()->create(['media_id' => $media->id, 'summary' => $summary, 'review_count' => 5, 'source_hash' => str_repeat('a', 64), 'generated_at' => now()->subDay()]);
}

test('five qualifying reviews make a summary from their text, newest first', function () {
    summarizeFake();
    $media = Media::factory()->create();
    reviewsOf($media, 5);

    runSummary($media);

    $row = ReviewSummary::query()->findOrFail($media->id);
    expect($row->summary)->toBe('Readers liked it.')->and($row->review_count)->toBe(5)->and($row->source_hash)->toHaveLength(64);

    Http::assertSent(fn (Request $request) => $request['text'] === implode("\n\n", ['Plain review 5.', 'Plain review 4.', 'Plain review 3.', 'Plain review 2.', 'Plain review 1.']));
});

test('fewer than five qualifying reviews: no summary, the existing one cleared, no ML call', function () {
    Http::fake();
    $media = Media::factory()->create();
    reviewsOf($media, 4);
    storedSummary($media);

    runSummary($media);

    expect(ReviewSummary::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('hidden, spoiler, deactivated and admin authors reviews are left out of the text sent to ML', function () {
    summarizeFake();
    $media = Media::factory()->create();
    reviewsOf($media, 5);
    Review::factory()->hidden()->create(['media_id' => $media->id, 'body' => 'HIDDEN text']);
    Review::factory()->spoiler()->create(['media_id' => $media->id, 'body' => 'SPOILER text']);
    Review::factory()->create(['media_id' => $media->id, 'body' => 'GONE text', 'user_id' => User::factory()->deactivated()]);
    Review::factory()->create(['media_id' => $media->id, 'body' => 'ADMIN text', 'user_id' => User::factory()->admin()]);
    Review::factory()->create(['media_id' => $media->id, 'body' => 'DELETED text'])->delete();

    runSummary($media);

    Http::assertSent(fn (Request $request) => str_contains($request['text'], 'Plain review 1.')
        && ! preg_match('/HIDDEN|SPOILER|GONE|ADMIN|DELETED/', $request['text']));
});

test('the text is capped at 20000 characters on a whole review', function () {
    summarizeFake();
    $media = Media::factory()->create();

    foreach (range(1, 6) as $i) {
        Review::factory()->create(['media_id' => $media->id, 'body' => str_repeat((string) $i, 4500), 'created_at' => now()->subMinutes(10 - $i)]);
    }

    runSummary($media);

    Http::assertSent(fn (Request $request) => mb_strlen($request['text']) <= 20000 && str_starts_with($request['text'], '666') && ! str_contains($request['text'], '1111'));
    expect(ReviewSummary::query()->find($media->id)->review_count)->toBe(4);
});

test('an unchanged source makes no ML call', function () {
    summarizeFake();
    $media = Media::factory()->create();
    reviewsOf($media, 5);

    runSummary($media);
    runSummary($media);

    Http::assertSentCount(1);
});

test('a null summary deletes the row', function () {
    summarizeFake(null);
    $media = Media::factory()->create();
    reviewsOf($media, 5);
    storedSummary($media);

    runSummary($media);

    expect(ReviewSummary::query()->count())->toBe(0);
});

test('ML down keeps the previous summary and does not throw', function () {
    Http::fake(['ml.test/v1/summarize' => Http::response('', 503)]);
    $media = Media::factory()->create();
    reviewsOf($media, 5);
    storedSummary($media);

    runSummary($media);

    expect(ReviewSummary::query()->find($media->id)->summary)->toBe('Old summary.');
});

test('hiding a review deletes the summary at once and queues a rebuild without its text', function () {
    Http::fake(['ml.test/v1/summarize' => Http::sequence()->push(['summary' => 'Readers liked it.', 'method' => 'lsa'])->push(['summary' => 'Fresh.', 'method' => 'lsa'])]);
    $media = Media::factory()->create();
    reviewsOf($media, 5);
    $secret = Review::factory()->create(['media_id' => $media->id, 'body' => 'SECRET opinion', 'created_at' => now()]);
    runSummary($media);
    Http::assertSent(fn (Request $request) => str_contains($request['text'], 'SECRET'));
    expect(ReviewSummary::query()->count())->toBe(1);

    app(HideReportedContent::class)->handle(User::factory()->admin()->create(), Report::factory()->of($secret)->create());

    expect(ReviewSummary::query()->count())->toBe(0);
    Queue::assertPushed(SummarizeReviews::class, fn (SummarizeReviews $job) => $job->mediaId === $media->id);

    runSummary($media);

    Http::assertSent(fn (Request $request) => ! str_contains($request['text'], 'SECRET') && str_contains($request['text'], 'Plain review 1.'));
    expect(ReviewSummary::query()->find($media->id)->summary)->toBe('Fresh.');
});

test('restoring a review queues a rebuild', function () {
    $review = Review::factory()->hidden()->create();
    $report = Report::factory()->of($review)->create();

    app(RestoreContent::class)->handle(User::factory()->admin()->create(), $report);

    Queue::assertPushed(SummarizeReviews::class, fn (SummarizeReviews $job) => $job->mediaId === $review->media_id);
});

test('deleting a review invalidates the summary', function () {
    $review = Review::factory()->create();
    storedSummary($review->media);

    app(DeleteReview::class)->handle($review->user, $review);

    expect(ReviewSummary::query()->count())->toBe(0);
    Queue::assertPushed(SummarizeReviews::class);
});

test('saving a review queues a rebuild and keeps the summary until then', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    storedSummary($media);

    app(SaveReview::class)->handle($user, $media, 'A new take', false);

    expect(ReviewSummary::query()->count())->toBe(1);
    Queue::assertPushed(SummarizeReviews::class, 1);
});

test('turning a review into a spoiler takes it out of the summary at once', function () {
    $review = Review::factory()->create();
    storedSummary($review->media);

    app(SaveReview::class)->handle($review->user, $review->media, $review->body, true);

    expect(ReviewSummary::query()->count())->toBe(0);
});

test('deactivating a reader invalidates the summaries of the stories they reviewed', function () {
    $author = User::factory()->create();
    $reviewed = Review::factory()->create(['user_id' => $author->id])->media;
    $spoiled = Review::factory()->spoiler()->create(['user_id' => $author->id])->media;
    $other = Media::factory()->create();
    storedSummary($reviewed);
    storedSummary($spoiled);
    storedSummary($other);

    $author->forceFill(['deactivated_at' => now()])->save();

    expect(ReviewSummary::query()->pluck('media_id')->all())->toEqualCanonicalizing([$spoiled->id, $other->id]);
    Queue::assertPushed(SummarizeReviews::class, 1);
    Queue::assertPushed(SummarizeReviews::class, fn (SummarizeReviews $job) => $job->mediaId === $reviewed->id);
});

test('the job is unique per story and delayed by the configured minutes', function () {
    config(['bookflix.social.review_summary_delay_minutes' => 10]);
    $media = Media::factory()->create();

    app(ReviewSummaries::class)->queue($media);

    expect(new SummarizeReviews($media->id))->toBeInstanceOf(ShouldBeUnique::class)
        ->and((new SummarizeReviews(7))->uniqueId())->toBe('7')
        ->and((new SummarizeReviews(7))->uniqueId())->not->toBe((new SummarizeReviews(8))->uniqueId());
    Queue::assertPushed(SummarizeReviews::class, fn (SummarizeReviews $job) => $job->queue === null
        && abs(now()->diffInSeconds($job->delay, false) - 600) < 5);
});
