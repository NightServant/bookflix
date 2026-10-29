<?php

use App\Enums\ReadingStatus;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\ReadingSession;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812]];

    $this->days = 'Array.from(document.querySelectorAll("[data-day] h2")).map((h) => h.textContent.trim()).join("|")';
    $this->titles = 'Array.from(document.querySelectorAll("[data-session] a[data-title]")).map((a) => a.textContent.trim()).join("|")';
});

/**
 * A reader with two stories and sessions on a few days (the clock is not frozen, so these are "days ago").
 */
function historyFixture(User $user): void
{
    $stories = Media::factory()->manga()->count(2)->sequence(
        ['title' => 'Alpha Tale', 'cover_url' => '/favicon.svg'],
        ['title' => 'Beta Tale', 'cover_url' => '/favicon.svg'],
    )->create();

    foreach ([[0, 0, 1800, 10, 14.5], [0, 1, 3600, 40, 48], [1, 0, 2700, 5, 10], [9, 1, 900, 30, 40]] as [$daysAgo, $story, $seconds, $from, $to]) {
        $end = Carbon::now()->startOfDay()->addHours(2)->subDays($daysAgo);
        ReadingSession::factory()->create(['user_id' => $user->id, 'media_id' => $stories[$story]->id, 'started_at' => $end->copy()->subSeconds($seconds), 'ended_at' => $end, 'seconds' => $seconds, 'from_percent' => $from, 'to_percent' => $to]);
    }

    LibraryEntry::factory()->for($user)->for($stories[1])->status(ReadingStatus::Completed)->create();
}

test('the history passes the shared checks on desktop and on a phone, with data and without', function () {
    foreach ([$this->desktop, $this->phone] as $viewport) {
        visit(route('history.index'), $viewport)->wait(1)->assertSee('No reading history yet')->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
    }

    historyFixture($this->user);

    foreach ([$this->desktop, $this->phone] as $viewport) {
        $page = visit(route('history.index'), $viewport)->wait(1);
        scrolledThrough($page);

        $page->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
    }
});

test('the days are headed and ordered, and the figures read as one line on a desktop and one row each on a phone', function () {
    historyFixture($this->user);

    $page = visit(route('history.index'), $this->desktop)->wait(1);

    $page->assertSee('Total reading time')
        ->assertScript('document.querySelector("[data-day] h2").textContent.trim()', 'Today')
        ->assertScript($this->titles, 'Alpha Tale|Beta Tale|Alpha Tale|Beta Tale')
        ->assertScript('(() => { const tops = Array.from(document.querySelectorAll("dl > div")).map((d) => Math.round(d.getBoundingClientRect().top)); return new Set(tops).size === 1; })()', true)
        // The middle figure counts sessions begun this month, which depends on the day the test runs.
        ->assertScript('(() => { const [time, , completed] = Array.from(document.querySelectorAll("dl dd")).map((d) => d.textContent.trim()); return time === "2h 30m" && completed === "1"; })()', true);

    visit(route('history.index'), $this->phone)->wait(1)
        ->assertScript('(() => { const tops = Array.from(document.querySelectorAll("dl > div")).map((d) => Math.round(d.getBoundingClientRect().top)); return new Set(tops).size === 3; })()', true);
});

test('nothing spills sideways on a phone, and every link on the page is a 44px target', function () {
    historyFixture($this->user);

    visit(route('history.index'), $this->phone + ['reducedMotion' => 'reduce'])->wait(1)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertScript('Array.from(document.querySelectorAll("main [data-session] a[data-title], nav[aria-label=\"Library sections\"] a")).every((el) => el.getBoundingClientRect().height >= 44)', true);
});

test('a long history pages by 25 and the pager moves between the pages', function () {
    $story = Media::factory()->create(['title' => 'Long Read', 'cover_url' => '/favicon.svg']);
    ReadingSession::factory()->count(60)->create(['user_id' => $this->user->id, 'media_id' => $story->id]);

    $page = visit(route('history.index'), $this->desktop)->wait(1);

    $page->assertScript('document.querySelectorAll("[data-session]").length', 25)
        ->assertSee('Page 1 of 3')
        ->click('Next')->wait(1)
        ->assertSee('Page 2 of 3')
        ->assertScript('document.querySelectorAll("[data-session]").length', 25)
        ->click('Next')->wait(1)
        ->assertSee('Page 3 of 3')
        ->assertScript('document.querySelectorAll("[data-session]").length', 10)
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues();
});

test('the library tabs lead to the history and back, and the history tab is the current one', function () {
    historyFixture($this->user);

    $page = visit(route('library.index'), $this->desktop)->wait(1);

    $page->click('nav[aria-label="Library sections"] a:has-text("History")')->wait(1)
        ->assertPathIs('/history')
        ->assertScript('document.querySelector("nav[aria-label=\"Library sections\"] a[aria-current=page]").textContent.trim()', 'History')
        ->click('nav[aria-label="Library sections"] a:has-text("Library")')->wait(1)
        ->assertPathIs('/library');
});

test('the history works the same with reduced motion', function () {
    historyFixture($this->user);

    visit(route('history.index'), $this->desktop + ['reducedMotion' => 'reduce'])->wait(1)
        ->assertSee('Today')->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
});
