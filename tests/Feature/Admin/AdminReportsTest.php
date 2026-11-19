<?php

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\ReviewState;
use App\Models\ExternalReview;
use App\Models\ExternalSource;
use App\Models\Media;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Admin, Reports: one card per reported thing (a reader's review or an outside review), oldest open first, with Hide, Dismiss and Restore.
 */

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Bookflix Admin']);
});

function reportsPage(array $query = [])
{
    return Livewire::withQueryParams($query)->test('pages::admin.moderation.index');
}

test('only an admin opens the page, and the menu shows how many things wait', function () {
    Report::factory()->count(2)->of(Review::factory()->create())->create();
    Report::factory()->of(Review::factory()->create())->create();

    $this->get(route('admin.moderation'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('admin.moderation'))->assertForbidden();

    $html = $this->actingAs($this->admin)->get(route('admin.moderation'))->assertOk()->assertSee('Reports')->getContent();

    expect(htmlQuery($html, '//a[@href="'.route('admin.moderation').'"][contains(normalize-space(),"Reports")][contains(normalize-space(),"2")]')->length)->toBeGreaterThan(0);
});

test('open reports are grouped per reported thing, oldest first, with the commonest reason and a quote', function () {
    ExternalSource::query()->updateOrCreate(['key' => 'hardcover'], ExternalSource::factory()->make(['key' => 'hardcover', 'name' => 'Hardcover'])->getAttributes());
    $media = Media::factory()->create(['title' => 'The Dispossessed']);
    $older = Review::factory()->create(['media_id' => $media->id, 'user_id' => User::factory()->create(['name' => 'Dana Whitlock'])->id, 'body' => 'Rude **words**']);
    $newer = ExternalReview::factory()->create(['source' => 'hardcover', 'author_name' => 'quietshelf', 'excerpt' => 'They are all clones']);
    Report::factory()->of($older)->create(['reason' => ReportReason::Abuse, 'details' => 'insulting other readers', 'created_at' => now()->subDays(2)]);
    Report::factory()->of($older)->create(['reason' => ReportReason::Abuse, 'created_at' => now()->subDay()]);
    Report::factory()->of($older)->create(['reason' => ReportReason::Spam, 'created_at' => now()->subHours(3)]);
    Report::factory()->of($newer)->create(['reason' => ReportReason::Spoiler, 'created_at' => now()->subHour()]);
    $this->actingAs($this->admin);

    $page = reportsPage()
        ->assertSeeHtmlInOrder(['The Dispossessed', 'Review by Dana Whitlock', 'Rude <strong>words</strong>', '3 reports', 'insulting other readers'])
        ->assertSee('From Hardcover, by quietshelf')
        ->assertSee('They are all clones')
        ->assertSee('Spoilers not marked');

    $cards = htmlQuery($page->html(), '//*[@data-report-card]');
    expect($cards->length)->toBe(2)
        ->and($cards->item(0)->textContent)->toContain('Abusive or hateful')->not->toContain('Spam or advertising')
        ->and($cards->item(1)->textContent)->toContain('Spoilers not marked');
});

test('hiding from the page hides the review and moves the card to Actioned, where Restore brings it back', function () {
    $review = Review::factory()->create();
    $report = Report::factory()->of($review)->create();
    Report::factory()->of($review)->create();
    $this->actingAs($this->admin);

    reportsPage()->call('hide', $report->id)->assertHasNoErrors()->assertDontSee('data-report-card', false);

    expect($review->fresh()->status)->toBe(ReviewState::Hidden)
        ->and(Report::query()->where('status', ReportStatus::Open)->count())->toBe(0);

    reportsPage(['status' => 'actioned'])
        ->assertSee('Hidden by Bookflix Admin')
        ->assertSee('closed 2 reports')
        ->call('restore', $report->id);

    expect($review->fresh()->status)->toBe(ReviewState::Published);
});

test('dismissing closes every open report about it and keeps the content', function () {
    $outside = ExternalReview::factory()->create();
    $report = Report::factory()->of($outside)->create();
    Report::factory()->of($outside)->create();
    $this->actingAs($this->admin);

    reportsPage()->call('dismiss', $report->id);

    expect(Report::query()->where('status', ReportStatus::Dismissed)->count())->toBe(2)
        ->and($outside->fresh()->hidden_at)->toBeNull();

    reportsPage(['status' => 'dismissed'])->assertSee('Dismissed by Bookflix Admin');
});

test('a review deleted since shows as removed, with Dismiss only', function () {
    $review = Review::factory()->create();
    Report::factory()->of($review)->create();
    $review->delete();
    $this->actingAs($this->admin);

    $html = reportsPage()->assertSee('Removed')->assertSee('The review was deleted by its author since')->html();

    expect(htmlQuery($html, '//*[@data-report-card]//button[normalize-space()="Hide"]')->length)->toBe(0)
        ->and(htmlQuery($html, '//*[@data-report-card]//button[normalize-space()="Dismiss"]')->length)->toBe(1);
});

test('the type and reason filters narrow the cards', function () {
    Report::factory()->of(Review::factory()->create(['body' => 'Reader words']))->create(['reason' => ReportReason::Spam]);
    Report::factory()->of(ExternalReview::factory()->create(['excerpt' => 'Outside words']))->create(['reason' => ReportReason::Spoiler]);
    $this->actingAs($this->admin);

    reportsPage(['type' => 'external_review'])->assertSee('Outside words')->assertDontSee('Reader words');
    reportsPage(['reason' => 'spam'])->assertSee('Reader words')->assertDontSee('Outside words');
    reportsPage(['type' => 'collection'])->assertSee('Reader words')->assertSee('Outside words');
});

test('nothing open says so', function () {
    $this->actingAs($this->admin);

    reportsPage()->assertSee('Nothing to look at');
});

test('the page reads a fixed number of queries however many cards it shows', function () {
    $count = function (int $n): int {
        foreach (range(1, $n) as $i) {
            Report::factory()->of(Review::factory()->create())->create();
            Report::factory()->of(ExternalReview::factory()->create())->create();
        }
        $this->actingAs($this->admin);
        DB::flushQueryLog();
        DB::enableQueryLog();
        reportsPage();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($count(5))->toBe($count(1));
});
