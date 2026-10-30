<?php

use App\Enums\ReadingStatus;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\ReadingSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

// The page groups by day, so the clock stands still, in the middle of a day.
beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-29 15:00:00')));

/**
 * One logged session of this reader that ended at this moment.
 */
function sessionOf(User $user, Media|string $media, Carbon|string $endedAt, int $seconds = 1800, float $from = 10, float $to = 20): ReadingSession
{
    $endedAt = Carbon::parse($endedAt);
    $media = $media instanceof Media ? $media : Media::factory()->create(['title' => $media]);

    return ReadingSession::factory()->create([
        'user_id' => $user->id,
        'media_id' => $media->id,
        'started_at' => $endedAt->copy()->subSeconds($seconds),
        'ended_at' => $endedAt,
        'seconds' => $seconds,
        'from_percent' => $from,
        'to_percent' => $to,
    ]);
}

/**
 * The text of each part of the page's list: the day headings, in order.
 *
 * @return list<string>
 */
function historyDays(string $html): array
{
    return collect(htmlQuery($html, '//main//section[@data-day]//h2'))->map(fn (DOMElement $day): string => trim(preg_replace('/\s+/', ' ', $day->textContent)))->all();
}

/**
 * The titles of the sessions listed, in page order.
 *
 * @return list<string>
 */
function historyTitles(string $html): array
{
    return collect(htmlQuery($html, '//main//li[@data-session]//a[@data-title]'))->map(fn (DOMElement $link): string => trim($link->textContent))->all();
}

test('sessions are listed newest first with the total reading time', function () {
    $user = User::factory()->create();
    sessionOf($user, 'Alpha', now()->subDay(), 3600);
    sessionOf($user, 'Beta', now(), 1800);

    $this->actingAs($user)->get(route('history.index'))->assertOk()->assertSeeInOrder(['Beta', 'Alpha'])->assertSee('1h 30m');
});

test('the history is for signed in, verified readers', function () {
    $this->get(route('history.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->get(route('history.index'))->assertRedirect(route('verification.notice'));
});

test('sessions are grouped by the day they were logged, the newest day first, and the newest session first inside a day', function () {
    $user = User::factory()->create();
    sessionOf($user, 'Today Early', '2026-10-29 08:00:00');
    sessionOf($user, 'Today Late', '2026-10-29 13:30:00');
    sessionOf($user, 'Yesterday', '2026-10-28 23:50:00');
    sessionOf($user, 'This Year', '2026-10-20 10:00:00');
    sessionOf($user, 'Last Year', '2025-12-03 10:00:00');

    $html = $this->actingAs($user)->get(route('history.index'))->getContent();

    expect(historyDays($html))->toBe(['Today', 'Yesterday', 'Tuesday, October 20', 'December 3, 2025'])
        ->and(historyTitles($html))->toBe(['Today Late', 'Today Early', 'Yesterday', 'This Year', 'Last Year'])
        ->and(htmlQuery($html, '//section[@data-day]//h2//time[@datetime="2026-10-29"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//section[@data-day="2026-10-29"]//li[@data-session]')->length)->toBe(2);
});

test('a session shows its story, how far it moved the reader and how long it took', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create(['title' => 'Slow Burn']);
    sessionOf($user, $media, now(), 5400, 12, 15.5);
    sessionOf($user, Media::factory()->create(['title' => 'No Clock']), now()->subMinute(), 0, 40, 41);

    $html = $this->actingAs($user)->get(route('history.index'))->getContent();
    $row = fn (string $title) => trim(preg_replace('/\s+/', ' ', htmlQuery($html, '//li[@data-session][.//a[@data-title][normalize-space()="'.$title.'"]]')->item(0)->textContent));

    expect(htmlQuery($html, '//li[@data-session]//a[@data-title][@href="'.route('stories.show', $media->slug).'"]')->length)->toBe(1)
        ->and($row('Slow Burn'))->toContain('12% ')->toContain('15.5%')->toContain('1h 30m')
        ->and($row('No Clock'))->toContain('40%')->toContain('41%')->not->toContain('0s')->not->toContain('0m');
});

test('a link to the story is the only link of a row, the cover being only for the pointer', function () {
    $user = User::factory()->create();
    sessionOf($user, Media::factory()->create(['title' => 'Linked', 'cover_url' => '/favicon.svg']), now());

    $html = $this->actingAs($user)->get(route('history.index'))->getContent();

    expect(htmlQuery($html, '//li[@data-session]//a')->length)->toBe(2)
        ->and(htmlQuery($html, '//li[@data-session]//a[@aria-hidden="true"][@tabindex="-1"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//li[@data-session]//a[@data-title]')->length)->toBe(1);
});

test('the figures above the list are the total reading time, the sessions this month and the stories completed, from the readers own data only', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    sessionOf($user, 'This Month A', '2026-10-28 10:00:00', 3600);
    sessionOf($user, 'This Month B', '2026-10-02 10:00:00', 1800);
    sessionOf($user, 'Last Month', '2026-09-30 22:00:00', 7200);
    sessionOf($other, 'Somebody Elses', '2026-10-28 10:00:00', 36000);
    LibraryEntry::factory()->for($user)->status(ReadingStatus::Completed)->count(2)->create();
    LibraryEntry::factory()->for($user)->status(ReadingStatus::Reading)->create();
    LibraryEntry::factory()->for($other)->status(ReadingStatus::Completed)->create();

    $html = $this->actingAs($user)->get(route('history.index'))->getContent();
    $figure = fn (string $label): string => trim(htmlQuery($html, '//dl//div[.//dt[normalize-space()="'.$label.'"]]//dd')->item(0)->textContent);

    expect($figure('Total reading time'))->toBe('3h 30m')
        ->and($figure('Sessions this month'))->toBe('2')
        ->and($figure('Stories completed'))->toBe('2');
});

test('the figures are a line separated by hairlines, never three cards', function () {
    $user = User::factory()->create();
    sessionOf($user, 'Any', now());

    $html = $this->actingAs($user)->get(route('history.index'))->getContent();

    expect(htmlQuery($html, '//dl')->length)->toBe(1)
        ->and(htmlQuery($html, '//dl[contains(@class,"divide-hairline")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//dl//div[contains(@class,"rounded") or contains(@class,"bg-surface") or contains(@class,"shadow") or contains(@class,"border ")]')->length)->toBe(0)
        ->and(htmlQuery($html, '//dl/div')->length)->toBe(3);
});

test('an empty history shows guidance and no figures, and says where to start', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('history.index'))->assertOk()->assertSee('No reading history yet');
    $html = $response->getContent();

    expect(htmlQuery($html, '//dl')->length)->toBe(0)
        ->and(htmlQuery($html, '//main//a[@href="'.route('library.index').'"][contains(@class,"min-h-11")]')->length)->toBeGreaterThanOrEqual(1)
        ->and(htmlQuery($html, '//main//a[@href="'.route('discover').'"]')->length)->toBeGreaterThanOrEqual(1);
});

test('long histories paginate by 25, and a later page has the rest', function () {
    $user = User::factory()->create();
    ReadingSession::factory()->count(60)->create(['user_id' => $user->id]);

    $page = Livewire::actingAs($user)->test('pages::history.index');

    expect($page->instance()->sessions)->toHaveCount(25)
        ->and($page->instance()->sessions->total())->toBe(60);

    $page->assertSee('Page 1 of 3')->call('nextPage')->call('nextPage');

    expect($page->instance()->sessions)->toHaveCount(10)->and($page->instance()->sessions->currentPage())->toBe(3);
});

test('a page number past the end shows the last page', function () {
    $user = User::factory()->create();
    ReadingSession::factory()->count(30)->create(['user_id' => $user->id]);

    $page = Livewire::actingAs($user)->withQueryParams(['page' => 9])->test('pages::history.index');

    expect($page->instance()->sessions)->toHaveCount(5)->and($page->instance()->sessions->currentPage())->toBe(2);
});

test('only the owners sessions appear, and nothing of another readers is read', function () {
    $user = User::factory()->create();
    sessionOf(User::factory()->create(), 'Not Yours', now(), 600);

    $this->actingAs($user)->get(route('history.index'))->assertOk()->assertDontSee('Not Yours')->assertSee('No reading history yet');
});

test('a session of a story behind the mature gate stays out of the list and the figures until the reader opts in', function () {
    $mature = matureStory('Hidden Tale');
    $plain = Media::factory()->create(['title' => 'Plain Tale']);

    $gated = User::factory()->create();
    $opted = User::factory()->showsMature()->create();

    foreach ([$gated, $opted] as $reader) {
        sessionOf($reader, $mature, now(), 3600);
        sessionOf($reader, $plain, now()->subMinute(), 1800);
        LibraryEntry::factory()->for($reader)->for($mature)->status(ReadingStatus::Completed)->create();
    }

    $closed = $this->actingAs($gated)->get(route('history.index'))->assertOk()->assertDontSee('Hidden Tale')->assertSee('Plain Tale')->getContent();
    $open = $this->actingAs($opted)->get(route('history.index'))->assertOk()->assertSee('Hidden Tale')->assertSee('Plain Tale')->getContent();
    $figure = fn (string $html, string $label): string => trim(htmlQuery($html, '//dl//div[.//dt[normalize-space()="'.$label.'"]]//dd')->item(0)->textContent);

    expect($figure($closed, 'Total reading time'))->toBe('30m')
        ->and($figure($closed, 'Stories completed'))->toBe('0')
        ->and($figure($open, 'Total reading time'))->toBe('1h 30m')
        ->and($figure($open, 'Stories completed'))->toBe('1');

    // Gated sessions stay stored: nothing was deleted, and a reader who opts in later gets them back.
    expect(ReadingSession::where('user_id', $gated->id)->count())->toBe(2);
});

test('a story that is deleted or merged away is not listed', function () {
    $user = User::factory()->create();
    $winner = Media::factory()->create(['title' => 'Winner']);
    $deleted = Media::factory()->create(['title' => 'Deleted Tale']);
    $merged = Media::factory()->create(['title' => 'Merged Tale', 'merged_into_id' => $winner->id]);
    sessionOf($user, $winner, now());
    sessionOf($user, $deleted, now()->subMinute());
    sessionOf($user, $merged, now()->subMinutes(2));
    $deleted->delete();

    $this->actingAs($user)->get(route('history.index'))->assertSee('Winner')->assertDontSee('Deleted Tale')->assertDontSee('Merged Tale');
});

test('the page sits under the library: a tab that says it is current, and the library stays the current item of the bar', function () {
    $html = $this->actingAs(User::factory()->create())->get(route('history.index'))->getContent();

    expect(htmlQuery($html, '//nav[@aria-label="Library sections"]//a[@aria-current="page"]')->length)->toBe(1)
        ->and(trim(htmlQuery($html, '//nav[@aria-label="Library sections"]//a[@aria-current="page"]')->item(0)->textContent))->toBe('History')
        ->and(collect(htmlQuery($html, '//nav[@aria-label="Library sections"]//a'))->map(fn (DOMElement $link): string => trim($link->textContent))->all())->toBe(['Library', 'Collections', 'History'])
        ->and(primaryNavs($html)['bottom'])->toMatch('#<a\s+href="'.preg_quote(route('library.index'), '#').'"[^>]*aria-current="page"#');
});

test('the page opens with a title and a guide', function () {
    $html = $this->actingAs(User::factory()->create())->get(route('history.index'))->getContent();

    expect(htmlQuery($html, '//main//h1')->length)->toBe(1)
        ->and(trim(htmlQuery($html, '//main//h1')->item(0)->textContent))->toBe('History');
});

test('the list runs a bounded number of queries however many sessions it holds', function () {
    $user = User::factory()->create();
    ReadingSession::factory()->count(40)->create(['user_id' => $user->id]);
    $this->actingAs($user);
    $user->load('profile');

    DB::enableQueryLog();
    $html = Livewire::test('pages::history.index')->html();

    // The sessions with their stories (two), their count, the figures (two), and the mature gate for the reader.
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(8)->and($html)->toContain('wire:key');
});

test('the duration reads as hours and minutes, seconds only under a minute', function (int $seconds, string $label) {
    expect(ReadingSession::duration($seconds))->toBe($label);
})->with([
    'under a minute' => [45, '45s'],
    'one minute' => [60, '1m'],
    'minutes' => [1500, '25m'],
    'an hour' => [3600, '1h'],
    'hours and minutes' => [5400, '1h 30m'],
    'a minute over an hour drops the seconds' => [3661, '1h 1m'],
    'past a day it stays in hours' => [100 * 3600 + 5 * 60, '100h 5m'],
    'nothing' => [0, '0s'],
]);
