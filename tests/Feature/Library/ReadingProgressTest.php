<?php

use App\Actions\Library\AddToLibrary;
use App\Actions\Library\LogProgress;
use App\Discovery\ContinueReading;
use App\Discovery\Shelf;
use App\Enums\ReadingStatus;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\ReadingProgress;
use App\Models\ReadingSession;
use App\Models\User;
use App\Models\UserInteraction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Livewire::withoutLazyLoading() lasts for the rest of the process unless its state is flushed.
afterEach(fn () => Livewire::flushState());

test('percent must be between 0 and 100', function (float $percent) {
    $user = User::factory()->create();
    $media = Media::factory()->create();

    expect(fn () => app(LogProgress::class)->handle($user, $media, $percent))->toThrow(ValidationException::class);
    expect(ReadingProgress::count())->toBe(0)->and(ReadingSession::count())->toBe(0)->and(UserInteraction::count())->toBe(0);
})->with([-1, 100.01, 101, NAN, INF, -INF]);

test('the percent error is on the percent key', function () {
    try {
        app(LogProgress::class)->handle(User::factory()->create(), Media::factory()->create(), 101);
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('percent');

        return;
    }

    $this->fail('No ValidationException was thrown.');
});

test('page, chapter and time are bounded too', function (array $arguments, string $key) {
    $user = User::factory()->create();

    try {
        app(LogProgress::class)->handle($user, Media::factory()->create(), 10, ...$arguments);
        $this->fail('No ValidationException was thrown.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($key);
    }

    expect(ReadingProgress::count())->toBe(0);
})->with([
    'page 0' => [['page' => 0], 'page'],
    'page negative' => [['page' => -4], 'page'],
    'page far too big' => [['page' => 1_000_001], 'page'],
    'chapter too long' => [['chapter' => str_repeat('a', 101)], 'chapter'],
    'negative time' => [['seconds' => -1], 'seconds'],
    'more than a day' => [['seconds' => 86_401], 'seconds'],
]);

test('the boundaries 0 and 100 are valid, and two decimals are kept', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();

    app(LogProgress::class)->handle($user, $media, 0);
    expect((float) ReadingProgress::sole()->percent)->toBe(0.0);

    app(LogProgress::class)->handle($user, $media, 12.5);
    expect(ReadingProgress::sole()->percent)->toBe('12.50');

    app(LogProgress::class)->handle($user, $media, 100);
    expect((float) ReadingProgress::sole()->percent)->toBe(100.0);
});

test('first log starts reading and records reading_start', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    app(LogProgress::class)->handle($user, $media, 12.5, chapter: '3');
    $entry = $user->library()->sole();
    expect($entry->status)->toBe(ReadingStatus::Reading)->and($entry->started_at)->not->toBeNull()
        ->and(UserInteraction::where('event', 'reading_start')->count())->toBe(1)
        ->and(ReadingProgress::sole()->chapter)->toBe('3');
});

test('progress events carry the delta as value', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    app(LogProgress::class)->handle($user, $media, 10);
    app(LogProgress::class)->handle($user, $media, 40);
    expect(UserInteraction::where('event', 'reading_progress')->orderBy('id')->pluck('value')->map(fn ($v) => (float) $v)->all())->toBe([10.0, 30.0]);
});

test('moving backwards records a zero delta, not a negative one', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    app(LogProgress::class)->handle($user, $media, 50);
    app(LogProgress::class)->handle($user, $media, 20);

    expect(UserInteraction::where('event', 'reading_progress')->orderBy('id')->pluck('value')->map(fn ($v) => (float) $v)->all())->toBe([50.0, 0.0])
        ->and((float) ReadingProgress::sole()->percent)->toBe(20.0);
});

test('reaching 100 completes the story once', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    app(LogProgress::class)->handle($user, $media, 99);
    app(LogProgress::class)->handle($user, $media, 100);
    $entry = $user->library()->sole();
    expect($entry->status)->toBe(ReadingStatus::Completed)->and($entry->completed_at)->not->toBeNull()
        ->and(UserInteraction::where('event', 'reading_complete')->count())->toBe(1);
    // re-read: back to 5 then 100 again
    $first = $entry->completed_at;
    app(LogProgress::class)->handle($user, $media, 5);
    app(LogProgress::class)->handle($user, $media, 100);
    expect(UserInteraction::where('event', 'reading_complete')->count())->toBe(1)->and($user->library()->sole()->completed_at->equalTo($first))->toBeTrue();
});

test('moving backwards neither lowers completed_at nor changes the completed status', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    app(LogProgress::class)->handle($user, $media, 100);
    $completedAt = $user->library()->sole()->completed_at;

    $this->travel(2)->days();
    app(LogProgress::class)->handle($user, $media, 30);

    $entry = $user->library()->sole();
    expect($entry->status)->toBe(ReadingStatus::Completed)->and($entry->completed_at->equalTo($completedAt))->toBeTrue()
        ->and((float) ReadingProgress::sole()->percent)->toBe(30.0);
});

test('a story logged straight to 100 is started and completed, in that order', function () {
    $user = User::factory()->create();
    app(LogProgress::class)->handle($user, Media::factory()->create(), 100);

    $entry = $user->library()->sole();
    expect($entry->status)->toBe(ReadingStatus::Completed)->and($entry->started_at)->not->toBeNull()->and($entry->completed_at)->not->toBeNull()
        ->and(array_values(array_diff(interactionEvents(), ['library_add'])))->toBe(['reading_start', 'reading_progress', 'reading_complete']);
});

test('logging resumes a paused or abandoned story and leaves a completed or reading one at its status', function (ReadingStatus $from, ReadingStatus $to) {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    app(AddToLibrary::class)->handle($user, $media, $from);

    app(LogProgress::class)->handle($user, $media, 40);

    expect($user->library()->sole()->status)->toBe($to);
})->with([
    'want to read' => [ReadingStatus::WantToRead, ReadingStatus::Reading],
    'paused' => [ReadingStatus::Paused, ReadingStatus::Reading],
    'abandoned' => [ReadingStatus::Abandoned, ReadingStatus::Reading],
    'reading' => [ReadingStatus::Reading, ReadingStatus::Reading],
    'completed' => [ReadingStatus::Completed, ReadingStatus::Completed],
]);

test('a story already set to reading by hand records no reading_start on its first log', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    app(AddToLibrary::class)->handle($user, $media, ReadingStatus::Reading);
    UserInteraction::query()->delete();

    app(LogProgress::class)->handle($user, $media, 10);

    expect(interactionEvents())->toBe(['reading_progress']);
});

test('a story that is not in the library is added by its first log', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();

    app(LogProgress::class)->handle($user, $media, 10);

    expect($user->library()->sole()->media_id)->toBe($media->id)->and(interactionEvents())->toContain('library_add');
});

test('every log appends a history session with from and to percent and seconds', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    app(LogProgress::class)->handle($user, $media, 20, seconds: 600);
    app(LogProgress::class)->handle($user, $media, 35, seconds: 300);
    $rows = ReadingSession::orderBy('id')->get();
    expect($rows->pluck('seconds')->all())->toBe([600, 300])->and((float) $rows[0]->from_percent)->toBe(0.0)->and((float) $rows[1]->from_percent)->toBe(20.0)->and((float) $rows[1]->to_percent)->toBe(35.0)
        ->and((int) $rows[1]->started_at->diffInSeconds($rows[1]->ended_at))->toBe(300);
});

test('there is one progress row per reader and story, and its last_read_at moves', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();

    app(LogProgress::class)->handle($user, $media, 10, page: 12, locator: ['cfi' => 'epubcfi(/6/4)']);
    $first = ReadingProgress::sole()->last_read_at;
    $this->travel(3)->hours();
    $returned = app(LogProgress::class)->handle($user, $media, 25);

    $row = ReadingProgress::sole();
    expect($row->last_read_at->greaterThan($first))->toBeTrue()->and($returned->is($row))->toBeTrue()
        ->and($row->page)->toBeNull()->and($row->locator)->toBeNull()
        ->and(ReadingSession::count())->toBe(2);

    app(LogProgress::class)->handle(User::factory()->create(), $media, 5);
    expect(ReadingProgress::count())->toBe(2);
});

test('page and locator are stored', function () {
    $user = User::factory()->create();
    app(LogProgress::class)->handle($user, Media::factory()->create(), 10, page: 12, locator: ['cfi' => 'epubcfi(/6/4)']);

    expect(ReadingProgress::sole()->page)->toBe(12)->and(ReadingProgress::sole()->locator)->toBe(['cfi' => 'epubcfi(/6/4)']);
});

test('opted out users still keep progress and history but no interactions', function () {
    $user = User::factory()->create();
    $user->profile()->updateOrCreate([], ['personalization_enabled' => false]);
    app(LogProgress::class)->handle($user, Media::factory()->create(), 50);
    expect(ReadingProgress::count())->toBe(1)->and(ReadingSession::count())->toBe(1)->and(UserInteraction::count())->toBe(0);
});

test('deleting the reader or the story takes their progress and history with it', function () {
    $user = User::factory()->create();
    $kept = User::factory()->create();
    $media = Media::factory()->create();
    app(LogProgress::class)->handle($user, $media, 50);
    app(LogProgress::class)->handle($kept, $media, 50);

    $user->delete();
    expect(ReadingProgress::count())->toBe(1)->and(ReadingSession::count())->toBe(1);

    $media->forceDelete();
    expect(ReadingProgress::count())->toBe(0)->and(ReadingSession::count())->toBe(0);
});

test('continue reading lists reading entries by last read time', function () {
    $user = User::factory()->create();
    [$a, $b, $done] = Media::factory()->count(3)->create();
    app(LogProgress::class)->handle($user, $a, 10);
    $this->travel(1)->hour();
    app(LogProgress::class)->handle($user, $b, 10);
    app(LogProgress::class)->handle($user, $done, 100);
    expect(app(ContinueReading::class)->shelf($user)->items->pluck('id')->all())->toBe([$b->id, $a->id]);
});

test('continue reading is a typed shelf with the progress each story is at', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create(['title' => 'Halfway Tale']);
    app(LogProgress::class)->handle($user, $media, 42.5, chapter: '7');

    $shelf = app(ContinueReading::class)->shelf($user);

    expect($shelf)->toBeInstanceOf(Shelf::class)->and($shelf->key)->toBe('continue-reading')->and($shelf->title)->toBe('Continue Reading')
        ->and((float) $shelf->items->first()->progress_percent)->toBe(42.5)->and($shelf->items->first()->progress_chapter)->toBe('7')
        ->and($shelf->items->first()->relationLoaded('mediaType'))->toBeTrue();
});

test('a story set to reading with no progress yet is listed after those with progress, and the shelf honours the limit', function () {
    $user = User::factory()->create();
    [$logged, $manual, $other] = Media::factory()->count(3)->create();
    app(AddToLibrary::class)->handle($user, $manual, ReadingStatus::Reading);
    app(LogProgress::class)->handle($user, $logged, 10);
    app(LogProgress::class)->handle($user, $other, 10);

    expect(app(ContinueReading::class)->shelf($user)->items->pluck('id')->all())->toBe([$other->id, $logged->id, $manual->id])
        ->and(app(ContinueReading::class)->shelf($user, 2)->items)->toHaveCount(2);
});

test('continue reading is only the readers own', function () {
    $mine = User::factory()->create();
    app(LogProgress::class)->handle(User::factory()->create(), Media::factory()->create(), 10);

    expect(app(ContinueReading::class)->shelf($mine))->toBeNull();
});

test('continue reading is null for a user with nothing in progress and the home page omits it', function () {
    $user = User::factory()->create();
    Media::factory()->create();
    expect(app(ContinueReading::class)->shelf($user))->toBeNull();
    $this->actingAs($user)->get(route('home'))->assertOk()->assertDontSee('Continue Reading');
});

test('a story behind the mature gate is left out of continue reading until the reader opts in', function () {
    $user = User::factory()->create();
    app(LogProgress::class)->handle($user, Media::factory()->create(['title' => 'Plain Tale']), 10);
    app(LogProgress::class)->handle($user, matureStory(), 10);

    expect(app(ContinueReading::class)->shelf($user)->items->pluck('title')->all())->toBe(['Plain Tale']);

    $user->profile()->updateOrCreate([], ['show_mature' => true, 'adult_confirmed_at' => now()]);
    expect(app(ContinueReading::class)->shelf($user->fresh())->items->pluck('title')->sort()->values()->all())->toBe(['Erotic Tale', 'Plain Tale']);

    $only = User::factory()->create();
    app(LogProgress::class)->handle($only, matureStory('Another Tale'), 10);
    expect(app(ContinueReading::class)->shelf($only))->toBeNull();
});

test('home opens with continue reading when the reader has something in progress, and the gate holds there too', function () {
    $user = User::factory()->create();
    Media::factory()->count(3)->create();
    app(LogProgress::class)->handle($user, Media::factory()->create(['title' => 'Halfway Tale', 'cover_url' => 'https://covers.test/halfway.jpg']), 42, chapter: '7');
    app(LogProgress::class)->handle($user, matureStory('Erotic Halfway'), 10);

    $html = $this->actingAs($user)->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain('Continue Reading')->toContain('Halfway Tale')->not->toContain('Erotic Halfway')
        ->and(strpos($html, 'id="shelf-continue-reading"'))->toBeLessThan(strpos($html, 'Trending Now'))
        ->and(htmlQuery($html, '//section[@aria-labelledby="shelf-continue-reading"]//*[@role="progressbar"][@aria-valuenow="42"]')->length)->toBe(1)
        ->and($html)->toContain('Chapter 7');

    $open = User::factory()->showsMature()->create();
    app(LogProgress::class)->handle($open, matureStory('Opted In Tale'), 10);
    expect($this->actingAs($open)->get(route('home'))->getContent())->toContain('Opted In Tale');
});

test('home with continue reading runs a bounded number of queries', function () {
    $user = User::factory()->create();
    Media::factory()->count(30)->create();

    foreach (Media::factory()->count(12)->create() as $media) {
        app(LogProgress::class)->handle($user, $media, 10);
    }

    $this->actingAs($user);
    DB::enableQueryLog();
    $this->get(route('home'))->assertOk()->assertSee('Continue Reading');

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(30);
});

test('users cannot log progress for another user through the component', function () {
    $this->actingAs($u = User::factory()->create());
    $media = Media::factory()->create();
    $other = User::factory()->create();
    app(LogProgress::class)->handle($other, $media, 70);

    Livewire::test('library-controls', ['media' => $media])->set('percent', 30)->call('logProgress');

    expect(ReadingProgress::where('user_id', $u->id)->sole()->percent)->toBe('30.00')
        ->and(ReadingProgress::where('user_id', $other->id)->sole()->percent)->toBe('70.00');
});

test('the progress form saves percent, chapter, page and minutes, and shows where the reader is', function () {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();

    $controls = Livewire::test('library-controls', ['media' => $media])->assertSee('Log progress')
        ->set('percent', '42.5')->set('chapter', 'Chapter 7')->set('pageNumber', '120')->set('minutes', '25')
        ->call('logProgress')->assertHasNoErrors();

    $progress = ReadingProgress::sole();
    expect((float) $progress->percent)->toBe(42.5)->and($progress->chapter)->toBe('Chapter 7')->and($progress->page)->toBe(120)
        ->and(ReadingSession::sole()->seconds)->toBe(1500)
        ->and($user->library()->sole()->status)->toBe(ReadingStatus::Reading);
    $controls->assertSee('42.5%');
});

test('the progress form needs a percent from 0 to 100, and bounds the rest', function (array $values, string $key) {
    $this->actingAs(User::factory()->create());
    $controls = Livewire::test('library-controls', ['media' => Media::factory()->create()]);

    foreach ($values as $name => $value) {
        $controls->set($name, $value);
    }

    $controls->call('logProgress')->assertHasErrors($key);
    expect(ReadingProgress::count())->toBe(0);
})->with([
    'no percent' => [['percent' => ''], 'percent'],
    'over 100' => [['percent' => '101'], 'percent'],
    'negative' => [['percent' => '-1'], 'percent'],
    'not a number' => [['percent' => 'NaN'], 'percent'],
    'infinity' => [['percent' => 'INF'], 'percent'],
    'text' => [['percent' => 'half'], 'percent'],
    'chapter too long' => [['percent' => '5', 'chapter' => str_repeat('a', 101)], 'chapter'],
    'page zero' => [['percent' => '5', 'pageNumber' => '0'], 'pageNumber'],
    'page with decimals' => [['percent' => '5', 'pageNumber' => '1.5'], 'pageNumber'],
    'a day and a minute' => [['percent' => '5', 'minutes' => '1441'], 'minutes'],
    'negative minutes' => [['percent' => '5', 'minutes' => '-3'], 'minutes'],
]);

test('the progress form clears itself after saving, and a repeat press saves the new value', function () {
    $this->actingAs($user = User::factory()->create());
    $media = Media::factory()->create();

    $controls = Livewire::test('library-controls', ['media' => $media])->set('percent', '10')->set('chapter', '2')->call('logProgress');
    expect($controls->get('chapter'))->toBe('')->and($controls->get('minutes'))->toBe('');

    $controls->set('percent', '20')->call('logProgress');
    expect((float) ReadingProgress::sole()->percent)->toBe(20.0)->and(ReadingSession::count())->toBe(2);
});

test('a reader sees only their own progress in the controls', function () {
    $media = Media::factory()->create();
    $owner = User::factory()->create();
    app(LogProgress::class)->handle($owner, $media, 64.5);

    $this->actingAs($other = User::factory()->create());
    Livewire::test('library-controls', ['media' => $media])->assertDontSee('64.5%')->assertSee('Log progress');

    Livewire::actingAs($owner)->test('library-controls', ['media' => $media])->assertSee('64.5%');
});

test('the progress form is not reachable for a guest, a story behind the mature gate, or an unverified reader', function () {
    $media = Media::factory()->create();

    Livewire::test('library-controls', ['media' => $media])->assertDontSee('Log progress');
    Livewire::test('library-controls', ['media' => $media])->set('percent', '10')->call('logProgress')->assertForbidden();

    $this->actingAs(User::factory()->unverified()->create());
    Livewire::test('library-controls', ['media' => $media])->set('percent', '10')->call('logProgress')->assertForbidden();

    $this->actingAs(User::factory()->create());
    Livewire::test('library-controls', ['media' => matureStory()])->set('percent', '10')->call('logProgress')->assertNotFound();

    expect(ReadingProgress::count())->toBe(0)->and(LibraryEntry::count())->toBe(0);
});

test('a reader who opted out keeps their progress but records nothing through the form', function () {
    $this->actingAs($user = User::factory()->create());
    $user->profile()->updateOrCreate([], ['personalization_enabled' => false]);

    Livewire::test('library-controls', ['media' => Media::factory()->create()])->set('percent', '33')->call('logProgress');

    expect(ReadingProgress::count())->toBe(1)->and(UserInteraction::count())->toBe(0);
});
