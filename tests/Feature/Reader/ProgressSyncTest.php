<?php

use App\Actions\Library\LogProgress;
use App\Enums\InteractionEvent;
use App\Models\ContentSource;
use App\Models\LibraryEntry;
use App\Models\ReadingProgress;
use App\Models\ReadingSession;
use App\Models\User;
use App\Models\UserInteraction;
use Illuminate\Support\Facades\Storage;

/*
 * The reader's save (P9-R13): POST reader/{source}/progress, through ProgressWriter and LogProgress. It answers 204, 403 when the access gate
 * says no, 422 for a payload that is not the agreed shape, and keeps one history row and one signal per reading session.
 */

beforeEach(function () {
    Storage::fake(ContentSource::disk());

    $this->reader = User::factory()->create();
    $this->source = ContentSource::factory()->publicDomain()->verified()->ready()->create();
    $this->save = fn (array $payload = [], ?ContentSource $source = null, ?User $as = null) => $this->actingAs($as ?? $this->reader)->postJson(
        route('reader.progress', $source ?? $this->source),
        [...['locator' => ['cfi' => 'epubcfi(/6/8!/4/2)', 'fraction' => 0.25], 'percent' => 25.5, 'chapter' => 'Chapter I', 'seconds' => 30, 'client_session' => 'session-aaaa1111', 'client_seq' => 1], ...$payload],
    );
});

test('the locator, percent and source are saved', function () {
    ($this->save)()->assertNoContent();

    $progress = ReadingProgress::sole();

    expect($progress->user_id)->toBe($this->reader->id)
        ->and($progress->media_id)->toBe($this->source->media_id)
        ->and($progress->content_source_id)->toBe($this->source->id)
        ->and($progress->locator)->toBe(['cfi' => 'epubcfi(/6/8!/4/2)', 'fraction' => 0.25])
        ->and($progress->percent)->toBe('25.50')
        ->and($progress->chapter)->toBe('Chapter I');
});

test('a page locator also fills the page', function () {
    ($this->save)(['locator' => ['page' => 7], 'percent' => 10])->assertNoContent();

    expect(ReadingProgress::sole()->page)->toBe(7);
});

test('a late save from the same page load is ignored, and still answers 204', function () {
    ($this->save)(['percent' => 40, 'client_seq' => 5])->assertNoContent();

    ($this->save)(['percent' => 10, 'client_seq' => 4])->assertNoContent();
    ($this->save)(['percent' => 10, 'client_seq' => 5])->assertNoContent();

    expect(ReadingProgress::sole()->percent)->toBe('40.00')->and(ReadingProgress::sole()->client_seq)->toBe(5)->and(ReadingSession::count())->toBe(1)
        ->and(UserInteraction::where('event', InteractionEvent::ReadingProgress)->count())->toBe(1);

    ($this->save)(['percent' => 50, 'client_seq' => 6])->assertNoContent();

    expect(ReadingProgress::sole()->percent)->toBe('50.00')->and(ReadingProgress::sole()->client_seq)->toBe(6);
});

test('no clock is read: a device with any clock saves, in its own order', function () {
    $this->travelTo(now()->subYears(3));

    ($this->save)(['percent' => 20, 'client_seq' => 1])->assertNoContent();

    $this->travelBack();

    ($this->save)(['percent' => 30, 'client_seq' => 2])->assertNoContent();
    ($this->save)(['percent' => 5, 'client_seq' => 1])->assertNoContent();

    expect(ReadingProgress::sole()->percent)->toBe('30.00')->and(ReadingProgress::sole()->client_seq)->toBe(2);
});

test('a save from a newer page load wins, whatever its number', function () {
    ($this->save)(['percent' => 40, 'client_session' => 'session-tab-one', 'client_seq' => 90])->assertNoContent();
    ($this->save)(['percent' => 15, 'client_session' => 'session-tab-two', 'client_seq' => 1])->assertNoContent();

    expect(ReadingProgress::sole()->percent)->toBe('15.00')->and(ReadingProgress::sole()->client_session)->toBe('session-tab-two');

    // The first tab's next save still counts against its own number only when it is the stored session again.
    ($this->save)(['percent' => 45, 'client_session' => 'session-tab-one', 'client_seq' => 91])->assertNoContent();

    expect(ReadingProgress::sole()->percent)->toBe('45.00');
});

test('a manual log leaves the pair alone and does not turn the check off', function () {
    ($this->save)(['percent' => 40, 'client_seq' => 5])->assertNoContent();

    app(LogProgress::class)->handle($this->reader, $this->source->media, 60.0);

    expect(ReadingProgress::sole()->client_session)->toBe('session-aaaa1111')->and(ReadingProgress::sole()->client_seq)->toBe(5);

    ($this->save)(['percent' => 10, 'client_seq' => 4])->assertNoContent();

    expect(ReadingProgress::sole()->percent)->toBe('60.00');
});

test('a reader save never extends a manual log\'s history row, nor one of another file', function () {
    app(LogProgress::class)->handle($this->reader, $this->source->media, 20.0, seconds: 100);

    ($this->save)(['percent' => 30, 'client_seq' => 1])->assertNoContent();

    expect(ReadingSession::count())->toBe(2)->and(ReadingSession::orderBy('id')->first()->content_source_id)->toBeNull();

    ($this->save)(['percent' => 35, 'client_seq' => 2])->assertNoContent();

    expect(ReadingSession::count())->toBe(2)->and(ReadingSession::orderByDesc('id')->first()->content_source_id)->toBe($this->source->id);

    $other = ContentSource::factory()->publicDomain()->verified()->ready()->create(['media_id' => $this->source->media_id]);

    ($this->save)(['percent' => 40, 'client_seq' => 3], $other)->assertNoContent();

    expect(ReadingSession::count())->toBe(3);
});

test('a bad payload is 422 and writes nothing', function (array $payload) {
    ($this->save)($payload)->assertUnprocessable();

    expect(ReadingProgress::count())->toBe(0)->and(ReadingSession::count())->toBe(0)->and(UserInteraction::count())->toBe(0);
})->with([
    'percent over 100' => [['percent' => 100.5]],
    'percent below 0' => [['percent' => -1]],
    'percent not a number' => [['percent' => 'half']],
    'no percent' => [['percent' => null]],
    'locator with an unknown key' => [['locator' => ['cfi' => 'x', 'path' => '/etc/passwd']]],
    'empty locator' => [['locator' => []]],
    'cfi too long' => [['locator' => ['cfi' => str_repeat('a', 2049)]]],
    'negative index' => [['locator' => ['index' => -1]]],
    'fraction over 1' => [['locator' => ['fraction' => 1.5]]],
    'page 0' => [['locator' => ['page' => 0]]],
    'locator not an array' => [['locator' => 'epubcfi(/6)']],
    'chapter too long' => [['chapter' => str_repeat('a', 101)]],
    'more than a day of seconds' => [['seconds' => 86401]],
    'no page load id' => [['client_session' => null]],
    'page load id with a bad character' => [['client_session' => 'abc def/ghi123']],
    'no save number' => [['client_seq' => null]],
]);

test('another reader\'s upload is 403', function () {
    $source = ContentSource::factory()->userOwned(User::factory()->create())->ready()->create();

    ($this->save)(source: $source)->assertForbidden();

    expect(ReadingProgress::count())->toBe(0);
});

test('a source that is unverified, or not ready, or a link out is 403', function (string $state) {
    $source = match ($state) {
        'unverified' => ContentSource::factory()->publicDomain()->ready()->create(),
        'pending' => ContentSource::factory()->publicDomain()->verified()->create(),
        'link' => ContentSource::factory()->licensed()->verified()->externalLink()->create(),
    };

    ($this->save)(source: $source)->assertForbidden();
})->with(['unverified', 'pending', 'link']);

test('a source withdrawn in the middle of a session turns the next save into 403', function () {
    ($this->save)()->assertNoContent();

    $this->source->update(['verified_at' => null, 'verified_by' => null]);

    ($this->save)(['percent' => 30])->assertForbidden();
    expect(ReadingProgress::sole()->percent)->toBe('25.50');
});

test('a guest is sent to sign in and an admin is refused', function () {
    $this->postJson(route('reader.progress', $this->source), [])->assertUnauthorized();

    ($this->save)(as: User::factory()->admin()->create())->assertForbidden();
    expect(ReadingProgress::count())->toBe(0);
});

test('finishing completes the story once and finishing again records no second reading_complete', function () {
    ($this->save)(['percent' => 100, 'locator' => ['fraction' => 1], 'client_seq' => 1])->assertNoContent();
    ($this->save)(['percent' => 100, 'locator' => ['fraction' => 1], 'client_seq' => 2])->assertNoContent();

    $entry = LibraryEntry::where('user_id', $this->reader->id)->sole();

    expect($entry->status->value)->toBe('completed')
        ->and($entry->completed_at)->not->toBeNull()
        ->and(UserInteraction::where('event', InteractionEvent::ReadingComplete)->count())->toBe(1);
});

test('an opted-out reader keeps progress and history but records no interactions', function () {
    $this->reader->profile()->updateOrCreate([], ['personalization_enabled' => false]);

    ($this->save)()->assertNoContent();

    expect(ReadingProgress::count())->toBe(1)->and(ReadingSession::count())->toBe(1)->and(UserInteraction::count())->toBe(0);
});

test('the seconds feed the reading history', function () {
    ($this->save)(['seconds' => 95])->assertNoContent();

    $session = ReadingSession::sole();

    expect($session->seconds)->toBe(95)->and($session->to_percent)->toBe('25.50')->and($session->from_percent)->toBe('0.00');
});

test('saves within the session gap extend one history row and record one reading_progress', function () {
    ($this->save)(['percent' => 10, 'seconds' => 60, 'client_seq' => 1])->assertNoContent();
    $this->travel(4)->minutes();
    ($this->save)(['percent' => 20, 'seconds' => 120, 'client_seq' => 2])->assertNoContent();
    $this->travel(4)->minutes();
    ($this->save)(['percent' => 30, 'seconds' => 30, 'client_seq' => 3])->assertNoContent();

    $session = ReadingSession::sole();

    expect($session->seconds)->toBe(210)
        ->and($session->from_percent)->toBe('0.00')
        ->and($session->to_percent)->toBe('30.00')
        ->and(UserInteraction::where('event', InteractionEvent::ReadingProgress)->count())->toBe(1)
        ->and(ReadingProgress::sole()->percent)->toBe('30.00');
});

test('a save after the gap starts a new history row and a new reading_progress', function () {
    ($this->save)(['percent' => 10, 'client_seq' => 1])->assertNoContent();
    $this->travel(11)->minutes();
    ($this->save)(['percent' => 20, 'client_seq' => 2])->assertNoContent();

    expect(ReadingSession::count())->toBe(2)->and(UserInteraction::where('event', InteractionEvent::ReadingProgress)->count())->toBe(2);
});

test('a beacon-style form payload field works', function () {
    $payload = json_encode(['locator' => ['page' => 3], 'percent' => 12, 'client_session' => 'session-aaaa1111', 'client_seq' => 1]);

    $this->actingAs($this->reader)->post(route('reader.progress', $this->source), ['payload' => $payload])->assertNoContent();

    expect(ReadingProgress::sole()->page)->toBe(3);
});

test('a beacon payload that is not json is 422 even though the client did not ask for json', function () {
    $this->actingAs($this->reader)->post(route('reader.progress', $this->source), ['payload' => '{not json'])->assertUnprocessable();

    expect(ReadingProgress::count())->toBe(0);
});
