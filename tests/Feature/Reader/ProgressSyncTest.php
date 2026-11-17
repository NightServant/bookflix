<?php

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
        [...['locator' => ['cfi' => 'epubcfi(/6/8!/4/2)', 'fraction' => 0.25], 'percent' => 25.5, 'chapter' => 'Chapter I', 'seconds' => 30, 'client_ts' => now()->getTimestampMs()], ...$payload],
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

test('an older client_ts after a newer one is ignored, and still answers 204', function () {
    $at = now()->getTimestampMs();

    ($this->save)(['percent' => 40, 'client_ts' => $at])->assertNoContent();

    ($this->save)(['percent' => 10, 'client_ts' => $at - 5000])->assertNoContent();
    ($this->save)(['percent' => 10, 'client_ts' => $at])->assertNoContent();

    expect(ReadingProgress::sole()->percent)->toBe('40.00')->and(ReadingProgress::sole()->client_ts)->toBe($at)->and(ReadingSession::count())->toBe(1);

    ($this->save)(['percent' => 50, 'client_ts' => $at + 1])->assertNoContent();

    expect(ReadingProgress::sole()->percent)->toBe('50.00')->and(ReadingProgress::sole()->client_ts)->toBe($at + 1);
});

test('a client clock far behind the server still saves, and its own order is kept', function () {
    $behind = now()->subYears(3)->getTimestampMs();

    ($this->save)(['percent' => 20, 'client_ts' => $behind])->assertNoContent();
    ($this->save)(['percent' => 30, 'client_ts' => $behind + 4000])->assertNoContent();
    ($this->save)(['percent' => 5, 'client_ts' => $behind + 2000])->assertNoContent();

    expect(ReadingProgress::sole()->percent)->toBe('30.00')->and(ReadingProgress::sole()->client_ts)->toBe($behind + 4000);
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
    'no client time' => [['client_ts' => null]],
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
    $at = now()->getTimestampMs();

    ($this->save)(['percent' => 100, 'locator' => ['fraction' => 1], 'client_ts' => $at])->assertNoContent();
    ($this->save)(['percent' => 100, 'locator' => ['fraction' => 1], 'client_ts' => $at + 1000])->assertNoContent();

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
    $at = now()->getTimestampMs();

    ($this->save)(['percent' => 10, 'seconds' => 60, 'client_ts' => $at])->assertNoContent();
    $this->travel(4)->minutes();
    ($this->save)(['percent' => 20, 'seconds' => 120, 'client_ts' => $at + 240_000])->assertNoContent();
    $this->travel(4)->minutes();
    ($this->save)(['percent' => 30, 'seconds' => 30, 'client_ts' => $at + 480_000])->assertNoContent();

    $session = ReadingSession::sole();

    expect($session->seconds)->toBe(210)
        ->and($session->from_percent)->toBe('0.00')
        ->and($session->to_percent)->toBe('30.00')
        ->and(UserInteraction::where('event', InteractionEvent::ReadingProgress)->count())->toBe(1)
        ->and(ReadingProgress::sole()->percent)->toBe('30.00');
});

test('a save after the gap starts a new history row and a new reading_progress', function () {
    $at = now()->getTimestampMs();

    ($this->save)(['percent' => 10, 'client_ts' => $at])->assertNoContent();
    $this->travel(11)->minutes();
    ($this->save)(['percent' => 20, 'client_ts' => $at + 660_000])->assertNoContent();

    expect(ReadingSession::count())->toBe(2)->and(UserInteraction::where('event', InteractionEvent::ReadingProgress)->count())->toBe(2);
});

test('a beacon-style form payload field works', function () {
    $payload = json_encode(['locator' => ['page' => 3], 'percent' => 12, 'client_ts' => now()->getTimestampMs()]);

    $this->actingAs($this->reader)->post(route('reader.progress', $this->source), ['payload' => $payload])->assertNoContent();

    expect(ReadingProgress::sole()->page)->toBe(3);
});

test('a beacon payload that is not json is 422 even though the client did not ask for json', function () {
    $this->actingAs($this->reader)->post(route('reader.progress', $this->source), ['payload' => '{not json'])->assertUnprocessable();

    expect(ReadingProgress::count())->toBe(0);
});
