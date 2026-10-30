<?php

use App\Actions\Library\AddToLibrary;
use App\Actions\Library\RemoveFromLibrary;
use App\Actions\Library\SetLibraryStatus;
use App\Actions\Library\ToggleFavorite;
use App\Enums\InteractionEvent;
use App\Enums\ReadingStatus;
use App\Events\LibraryChanged;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\User;
use App\Models\UserInteraction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;

test('opted out users create no interaction rows through any action', function () {
    $user = User::factory()->create();
    $user->profile()->updateOrCreate([], ['personalization_enabled' => false]);
    $media = Media::factory()->create();

    app(AddToLibrary::class)->handle($user, $media);
    app(ToggleFavorite::class)->handle($user, $media);
    app(SetLibraryStatus::class)->handle($user, $media, ReadingStatus::Abandoned);

    expect(UserInteraction::count())->toBe(0)->and(LibraryEntry::count())->toBe(1);
});

test('adding twice keeps one row and one library_add', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();

    app(AddToLibrary::class)->handle($user, $media);
    app(AddToLibrary::class)->handle($user, $media);

    expect(LibraryEntry::count())->toBe(1)
        ->and(UserInteraction::where('event', 'library_add')->count())->toBe(1);
});

test('the surface a story is added from is kept in the interaction context, and only the keys the recorder allows', function () {
    $user = User::factory()->create();
    [$added, $moved] = Media::factory()->count(2)->create();

    app(AddToLibrary::class)->handle($user, $added, ReadingStatus::Completed, ['surface' => 'onboarding', 'ip' => '10.0.0.1']);
    app(SetLibraryStatus::class)->handle($user, $moved, ReadingStatus::Reading, ['surface' => 'import']);
    app(AddToLibrary::class)->handle($user, $added, ReadingStatus::Completed, ['surface' => 'again']);

    expect(UserInteraction::orderBy('id')->get()->map(fn (UserInteraction $row) => $row->context)->all())->toBe([['surface' => 'onboarding'], ['surface' => 'import']]);
});

test('adding is idempotent: a second add with another status leaves the entry as it was', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();

    $first = app(AddToLibrary::class)->handle($user, $media);
    $again = app(AddToLibrary::class)->handle($user, $media, ReadingStatus::Completed);

    expect($first->wasRecentlyCreated)->toBeTrue()
        ->and($again->wasRecentlyCreated)->toBeFalse()
        ->and($again->status)->toBe(ReadingStatus::WantToRead)
        ->and($again->completed_at)->toBeNull();
});

test('a story is added as want to read unless another status is asked for', function () {
    $user = User::factory()->create();
    [$a, $b, $c] = Media::factory()->count(3)->create();

    $default = app(AddToLibrary::class)->handle($user, $a);
    $reading = app(AddToLibrary::class)->handle($user, $b, ReadingStatus::Reading);
    $done = app(AddToLibrary::class)->handle($user, $c, ReadingStatus::Completed);

    expect($default->status)->toBe(ReadingStatus::WantToRead)->and($default->started_at)->toBeNull()
        ->and($reading->started_at)->not->toBeNull()->and($reading->completed_at)->toBeNull()
        ->and($done->completed_at)->not->toBeNull();
});

test('favoriting an unlisted story adds it as want to read', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();

    $entry = app(ToggleFavorite::class)->handle($user, $media);

    expect($entry->status)->toBe(ReadingStatus::WantToRead)->and($entry->favorited_at)->not->toBeNull()
        ->and(interactionEvents())->toBe(['library_add', 'favorite'])
        ->and((float) UserInteraction::where('event', 'favorite')->sole()->value)->toBe(1.0);

    expect(app(ToggleFavorite::class)->handle($user, $media)->favorited_at)->toBeNull()
        ->and(UserInteraction::where('event', 'favorite')->count())->toBe(1);
});

test('favoriting a story already in the library keeps its status', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    app(SetLibraryStatus::class)->handle($user, $media, ReadingStatus::Reading);

    $entry = app(ToggleFavorite::class)->handle($user, $media);

    expect($entry->status)->toBe(ReadingStatus::Reading)->and(LibraryEntry::count())->toBe(1);
});

test('removing deletes the row and its favorite', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    app(ToggleFavorite::class)->handle($user, $media);

    app(RemoveFromLibrary::class)->handle($user, $media);

    expect(LibraryEntry::count())->toBe(0);
});

test('removing only touches the signed in readers own row', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $media = Media::factory()->create();
    app(AddToLibrary::class)->handle($other, $media);

    app(RemoveFromLibrary::class)->handle($me, $media);

    expect(LibraryEntry::where('user_id', $other->id)->count())->toBe(1);
});

test('status changes stamp started_at and completed_at', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();

    $entry = app(SetLibraryStatus::class)->handle($user, $media, ReadingStatus::Reading);
    expect($entry->started_at)->not->toBeNull()->and($entry->completed_at)->toBeNull();

    $this->travel(1)->hour();
    $completed = app(SetLibraryStatus::class)->handle($user, $media, ReadingStatus::Completed);
    $stamp = $completed->completed_at;
    expect($stamp)->not->toBeNull();

    // Moving on from completed, and completing again later, never rewrites the first completion.
    $this->travel(1)->hour();
    app(SetLibraryStatus::class)->handle($user, $media, ReadingStatus::Reading);
    expect(app(SetLibraryStatus::class)->handle($user, $media, ReadingStatus::Completed)->completed_at->equalTo($stamp))->toBeTrue();
});

test('setting a status on an unlisted story adds it', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();

    $entry = app(SetLibraryStatus::class)->handle($user, $media, ReadingStatus::Paused);

    expect($entry->status)->toBe(ReadingStatus::Paused)->and(LibraryEntry::count())->toBe(1)
        ->and(interactionEvents())->toBe(['library_add']);
});

test('abandoning records abandon once, and a status change alone records no completion', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();

    app(SetLibraryStatus::class)->handle($user, $media, ReadingStatus::Completed);
    expect(UserInteraction::where('event', 'reading_complete')->count())->toBe(0);

    app(SetLibraryStatus::class)->handle($user, $media, ReadingStatus::Abandoned);
    app(SetLibraryStatus::class)->handle($user, $media, ReadingStatus::Abandoned);

    expect(UserInteraction::where('event', InteractionEvent::Abandon->value)->count())->toBe(1);
});

test('library actions dispatch LibraryChanged', function () {
    Event::fake([LibraryChanged::class]);
    $user = User::factory()->create();
    $media = Media::factory()->create();

    app(AddToLibrary::class)->handle($user, $media);
    app(SetLibraryStatus::class)->handle($user, $media, ReadingStatus::Reading);
    app(ToggleFavorite::class)->handle($user, $media);
    app(RemoveFromLibrary::class)->handle($user, $media);

    Event::assertDispatchedTimes(LibraryChanged::class, 4);
    Event::assertDispatched(LibraryChanged::class, fn (LibraryChanged $event): bool => $event->user->is($user) && $event->media->is($media));
});

test('removing a story that is not in the library dispatches nothing', function () {
    Event::fake([LibraryChanged::class]);

    app(RemoveFromLibrary::class)->handle(User::factory()->create(), Media::factory()->create());

    Event::assertNotDispatched(LibraryChanged::class);
});

test('deleting a user cascades library and interactions', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    app(AddToLibrary::class)->handle($user, $media);

    $user->delete();

    expect(LibraryEntry::count())->toBe(0)->and(UserInteraction::count())->toBe(0);
});

test('a media removed for good takes its library entries with it and keeps the interactions, unlinked', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    app(AddToLibrary::class)->handle($user, $media);

    $media->forceDelete();

    expect(LibraryEntry::count())->toBe(0)->and(UserInteraction::sole()->media_id)->toBeNull();
});

test('one reader cannot have the same story twice, at the database level', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    LibraryEntry::factory()->for($user)->for($media)->create();

    expect(fn () => LibraryEntry::factory()->for($user)->for($media)->create())->toThrow(UniqueConstraintViolationException::class);
});

test('the user relation lists their entries', function () {
    $user = User::factory()->create();
    LibraryEntry::factory()->count(2)->for($user)->create();
    LibraryEntry::factory()->create();

    expect($user->library)->toHaveCount(2);
});
