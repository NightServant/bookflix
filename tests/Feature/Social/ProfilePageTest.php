<?php

use App\Enums\AgeRating;
use App\Enums\ReadingStatus;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Genre;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\Rating;
use App\Models\ReadingProgress;
use App\Models\ReadingSession;
use App\Models\Review;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-19 15:00:00')));

/**
 * A reader with a name, a headline and the languages they read in.
 */
function profileOwner(array $profile = []): User
{
    $user = User::factory()->create(['name' => 'Yoko Reyes', 'created_at' => Carbon::parse('2025-03-04')]);
    $user->profile->update(['headline' => 'Short manga and lighthouses', 'preferred_languages' => ['en', 'ja'], ...$profile]);

    return $user->refresh();
}

function libraryEntryOf(User $user, ReadingStatus $status, array $media = [], array $entry = []): LibraryEntry
{
    return LibraryEntry::factory()->status($status)->create(['user_id' => $user->id, 'media_id' => Media::factory()->create($media)->id, ...$entry]);
}

test('only a signed-in reader can open their profile page', function () {
    $this->get(route('profile.show'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->admin()->create())->get(route('profile.show'))->assertRedirect(route('admin.index'));
});

test('the header shows the reader with a reminder that the page is private', function () {
    $this->actingAs(profileOwner())->get(route('profile.show'))
        ->assertOk()
        ->assertSee('Yoko Reyes')
        ->assertSee('Short manga and lighthouses')
        ->assertSee('Reader since March 2025')
        ->assertSee('Reads in English and Japanese')
        ->assertSee('Only you can see this page')
        ->assertSee('href="'.route('profile.edit').'"', false)
        ->assertSee('href="'.route('privacy.edit').'"', false)
        ->assertSee('href="'.route('privacy.edit').'#export"', false);
});

test('there is no profile page for anyone else', function () {
    $other = profileOwner();

    $this->actingAs(User::factory()->create())->get(route('profile.show'))->assertOk()->assertDontSee('Yoko Reyes')->assertDontSee('Short manga and lighthouses');
    $this->actingAs(User::factory()->create())->get('/profile/'.$other->id)->assertNotFound();
});

test('your reading counts this year against last year, with the time read, reviews and collections', function () {
    $user = profileOwner();

    foreach (range(1, 3) as $i) {
        libraryEntryOf($user, ReadingStatus::Completed, entry: ['completed_at' => now()->subMonths($i)]);
    }
    libraryEntryOf($user, ReadingStatus::Completed, entry: ['completed_at' => Carbon::parse('2025-06-01')]);
    ReadingSession::factory()->create(['user_id' => $user->id, 'seconds' => 7200, 'started_at' => now()->subWeek(), 'ended_at' => now()->subWeek()->addHours(2)]);
    Review::factory()->create(['user_id' => $user->id, 'created_at' => now()->subDays(2)]);
    Review::factory()->create(['user_id' => $user->id, 'created_at' => Carbon::parse('2026-01-10')]);
    $collection = Collection::factory()->create(['user_id' => $user->id]);
    CollectionItem::factory()->count(2)->create(['collection_id' => $collection->id]);

    $html = $this->actingAs($user)->get(route('profile.show'))->assertOk()->getContent();
    $stat = fn (string $name): string => trim(preg_replace('/\s+/', ' ', htmlQuery($html, '//*[@data-stat="'.$name.'"]')->item(0)->textContent));

    expect($stat('finished'))->toContain('3')->toContain('2 more than last year')
        ->and($stat('time'))->toContain('2h')
        ->and($stat('reviews'))->toContain('2')->toContain('1 this month')
        ->and($stat('collections'))->toContain('1')->toContain('2 stories in them');
});

test('stories behind the mature gate are left out of every part of the page', function () {
    $user = profileOwner();
    libraryEntryOf($user, ReadingStatus::Reading, ['title' => 'Gated Tale', 'age_rating' => AgeRating::Adult]);
    libraryEntryOf($user, ReadingStatus::Completed, ['title' => 'Gated Finish', 'age_rating' => AgeRating::Adult]);
    Review::factory()->create(['user_id' => $user->id, 'media_id' => Media::factory()->create(['title' => 'Gated Review', 'age_rating' => AgeRating::Adult])->id]);

    $this->actingAs($user)->get(route('profile.show'))->assertOk()
        ->assertDontSee('Gated Tale')->assertDontSee('Gated Finish')->assertDontSee('Gated Review')
        ->assertSee('Nothing on the go.');
});

test('currently reading lists the three most recently read stories, the first with the red button', function () {
    $user = profileOwner();

    foreach (['Oldest' => 4, 'Newest' => 0, 'Middle' => 2, 'Older' => 3] as $title => $days) {
        $entry = libraryEntryOf($user, ReadingStatus::Reading, ['title' => $title]);
        ReadingProgress::factory()->create(['user_id' => $user->id, 'media_id' => $entry->media_id, 'percent' => 34, 'last_read_at' => now()->subDays($days)]);
    }

    $html = $this->actingAs($user)->get(route('profile.show'))->assertOk()->assertSeeInOrder(['Newest', 'Middle', 'Older'])->assertDontSee('Oldest')->getContent();

    $buttons = htmlQuery($html, '//*[@data-section="reading"]//a[@data-continue]');
    expect($buttons->length)->toBe(3)
        ->and(trim($buttons->item(0)->textContent))->toBe('Continue reading')
        ->and($buttons->item(0)->getAttribute('class'))->toContain('--color-accent')
        ->and($buttons->item(1)->getAttribute('class'))->not->toContain('--color-accent')
        ->and($html)->toContain('34% · Last read');
});

test('finished stories are grouped by year with a few covers and how many more', function () {
    $user = profileOwner();

    foreach (range(1, 9) as $i) {
        libraryEntryOf($user, ReadingStatus::Completed, entry: ['completed_at' => Carbon::parse('2026-02-01')->addDays($i * 20)]);
    }
    libraryEntryOf($user, ReadingStatus::Completed, entry: ['completed_at' => Carbon::parse('2025-04-10')]);

    $html = $this->actingAs($user)->get(route('profile.show'))->assertOk()->getContent();
    $years = htmlQuery($html, '//*[@data-section="finished"]//*[@data-year]');

    expect($years->length)->toBe(2)
        ->and($years->item(0)->getAttribute('data-year'))->toBe('2026')
        ->and(htmlQuery($html, '//*[@data-year="2026"]//*[@data-cover]')->length)->toBe(7)
        ->and(preg_replace('/\s+/', ' ', $years->item(0)->textContent))->toContain('9 stories')->toContain('+2')->toContain('Feb – Jul')
        ->and(htmlQuery($html, '//*[@data-year="2025"]//*[@data-cover]')->length)->toBe(1);
});

test('your reviews show the latest two, keep spoilers closed and say when one is hidden', function () {
    $user = profileOwner();
    Review::factory()->create(['user_id' => $user->id, 'body' => 'An **old** take', 'created_at' => now()->subWeeks(3)]);
    Review::factory()->hidden()->create(['user_id' => $user->id, 'body' => 'Hidden words', 'created_at' => now()->subWeek()]);
    Review::factory()->spoiler()->create(['user_id' => $user->id, 'body' => 'The butler did it', 'created_at' => now()->subDay()]);

    $this->actingAs($user);

    Livewire::test('pages::profile.show')
        ->assertSee('Marked as spoilers. Open the story to read it.')
        ->assertDontSee('The butler did it')
        ->assertSee('A moderator hid this review. Only you can see it.')
        ->assertDontSee('An <strong>old</strong> take', false)
        ->assertSee('Show all 3 reviews')
        ->call('showAllReviews')
        ->assertSee('An <strong>old</strong> take', false)
        ->assertDontSee('Show all 3 reviews');
});

test('collections show the latest three with a link to all of them', function () {
    $user = profileOwner();

    foreach (range(1, 4) as $i) {
        Collection::factory()->create(['user_id' => $user->id, 'name' => "List {$i}", 'updated_at' => now()->subDays($i)]);
    }

    $this->actingAs($user)->get(route('profile.show'))->assertOk()
        ->assertSeeInOrder(['List 1', 'List 2', 'List 3'])->assertDontSee('List 4')
        ->assertSee('Show all 4 collections');
});

test('profile strength counts what is done and links to what is not', function () {
    $user = profileOwner(['headline' => null, 'bio' => 'I read slowly.']);
    $genre = Genre::factory()->create(['name' => 'Seinen']);
    UserPreference::factory()->create(['user_id' => $user->id, 'preferable_type' => 'genre', 'preferable_id' => $genre->id]);
    Rating::factory()->count(5)->create(['user_id' => $user->id]);

    $html = $this->actingAs($user)->get(route('profile.show'))->assertOk()->assertSee('Seinen')->assertSee('I read slowly.')->getContent();
    $strength = preg_replace('/\s+/', ' ', htmlQuery($html, '//*[@data-section="strength"]')->item(0)->textContent);

    expect($strength)->toContain('Good · 3 of 6 done')
        ->and(htmlQuery($html, '(//*[@data-section="strength"])[1]//li[@data-done]')->length)->toBe(3)
        ->and(htmlQuery($html, '(//*[@data-section="strength"])[1]//li[not(@data-done)]//a')->length)->toBe(3);
});

test('a new reader sees empty states and a prompt to write a bio', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.show'))->assertOk()
        ->assertSee('Nothing on the go.')
        ->assertSee('Getting started · 0 of 6 done')
        ->assertSee('Add a few lines about what you like to read');
});

test('the page reads a fixed number of queries however much the reader has', function () {
    $count = function (int $n): int {
        $user = profileOwner();

        foreach (range(1, $n) as $i) {
            $reading = libraryEntryOf($user, ReadingStatus::Reading);
            ReadingProgress::factory()->create(['user_id' => $user->id, 'media_id' => $reading->media_id, 'last_read_at' => now()->subHours($i)]);
            libraryEntryOf($user, ReadingStatus::Completed, entry: ['completed_at' => now()->subDays($i)]);
            Review::factory()->create(['user_id' => $user->id]);
            $collection = Collection::factory()->create(['user_id' => $user->id]);
            CollectionItem::factory()->create(['collection_id' => $collection->id]);
        }

        $this->actingAs($user);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('profile.show'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $few = $count(1);
    $many = $count(6);

    // Budget: the user and profile, the figures (library, reading time, reviews, collections, ratings, genres), currently reading with its
    // progress and sources, two years of finished covers, the latest reviews and collections with their previews, plus the layout's own.
    expect($many)->toBe($few)->toBeLessThanOrEqual(40);
});
