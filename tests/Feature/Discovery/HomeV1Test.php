<?php

use App\Discovery\HomeShelves;
use App\Discovery\Shelf;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

// Livewire::withoutLazyLoading() lasts for the rest of the process unless its state is flushed.
afterEach(fn () => Livewire::flushState());

/**
 * Stories with covers, rated from most to least, so trending has an order.
 *
 * @return Collection<int, Media>
 */
function ratedStories(int $count, array $attributes = []): Collection
{
    return Media::factory()->count($count)->sequence(fn ($sequence) => [
        'title' => 'Rated Story '.($sequence->index + 1),
        'ratings_count' => 1000 - $sequence->index * 10,
        'cover_url' => 'https://covers.test/'.($sequence->index + 1).'.jpg',
        ...$attributes,
    ])->create();
}

test('home shows trending, new releases and explore by subject in order', function () {
    Media::factory()->count(5)->create();
    $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()
        ->assertSeeInOrder(['Trending Now', 'New Releases', 'Explore by Subject']);
});

test('trending is the only gradient shelf', function () {
    Media::factory()->count(3)->create();
    $html = $this->actingAs(User::factory()->create())->get(route('home'))->getContent();
    expect(substr_count($html, 'from-featured-from'))->toBe(1);
});

test('an empty catalog still renders an empty state without errors', function () {
    $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()->assertSee('The catalog is empty')->assertSee('Discover');
});

test('a reader with an empty catalog gets the explanation, an admin also the sync', function () {
    $this->actingAs(User::factory()->create())->get(route('home'))
        ->assertSee('Stories appear here once the catalog has been synced')
        ->assertDontSee('Sync providers')
        ->assertSee(route('profile.edit'));

    $this->actingAs(User::factory()->admin()->create())->get(route('home'))
        ->assertSee('Sync providers')
        ->assertSee(route('admin.providers'));
});

test('the empty state has the one h1 of the page and no shelves', function () {
    $html = $this->actingAs(User::factory()->create())->get(route('home'))->getContent();

    expect(htmlQuery($html, '//main//h1')->length)->toBe(1)
        ->and(htmlQuery($html, '//main//h1')->item(0)->textContent)->toContain('The catalog is empty')
        ->and($html)->not->toContain('Trending Now');
});

test('guest safe shelves do not depend on a user', function () {
    Media::factory()->count(3)->create();
    expect(app(HomeShelves::class)->guestSafe()->pluck('key')->all())->toBe(['trending', 'new-releases', 'explore-by-subject']);
});

test('the shelves are typed, titled in title case, and only trending is featured', function () {
    Media::factory()->count(2)->create();
    $shelves = app(HomeShelves::class)->guestSafe();

    expect($shelves)->each->toBeInstanceOf(Shelf::class)
        ->and($shelves->pluck('title')->all())->toBe(['Trending Now', 'New Releases', 'Explore by Subject'])
        ->and($shelves->pluck('featured')->all())->toBe([true, false, false]);
});

test('home runs a bounded number of queries without lazy loading', function () {
    Media::factory()->count(30)->create();
    $this->actingAs(User::factory()->create());
    DB::enableQueryLog();
    $this->get(route('home'))->assertOk();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(25);
});

test('each shelf loads in a bounded number of queries without lazy loading', function (string $shelf) {
    $this->seed(DeweySeeder::class);
    classified(ratedStories(30, ['published_on' => now()->subDays(5)]), '813');
    $this->actingAs(User::factory()->create());
    Livewire::withoutLazyLoading();

    DB::enableQueryLog();
    Livewire::test('home-shelf', ['shelf' => $shelf])->assertSee($shelf === 'explore-by-subject' ? 'Literature' : 'Rated Story 1');

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(14);
})->with(['trending', 'new-releases', 'explore-by-subject']);

test('new releases are ordered by published date and exclude unpublished future dates beyond a month', function () {
    Media::factory()->create(['title' => 'Old', 'published_on' => now()->subYears(2)]);
    Media::factory()->create(['title' => 'New', 'published_on' => now()->subDays(3)]);
    Media::factory()->create(['title' => 'Far Future', 'published_on' => now()->addMonths(6)]);
    Media::factory()->create(['title' => 'Undated']);
    $shelf = app(HomeShelves::class)->guestSafe()->firstWhere('key', 'new-releases');
    expect($shelf->items->pluck('title')->all())->toBe(['New', 'Old']);
});

test('trending is the most rated first, newest breaking ties, and skips merged stories', function () {
    $winner = Media::factory()->create(['title' => 'Winner', 'ratings_count' => 500]);
    Media::factory()->create(['title' => 'Tie Old', 'ratings_count' => 100, 'published_on' => now()->subYears(3)]);
    Media::factory()->create(['title' => 'Tie New', 'ratings_count' => 100, 'published_on' => now()->subDays(2)]);
    Media::factory()->create(['title' => 'Merged', 'ratings_count' => 900, 'merged_into_id' => $winner->id]);

    $shelf = app(HomeShelves::class)->guestSafe()->firstWhere('key', 'trending');

    expect($shelf->items->pluck('title')->all())->toBe(['Winner', 'Tie New', 'Tie Old']);
});

test('new releases favour stories that are not already trending, and fall back to them when there are no others', function () {
    ratedStories(4, ['published_on' => now()->subYear()]);
    Media::factory()->create(['title' => 'Fresh Arrival', 'ratings_count' => 0, 'published_on' => now()->subDay()]);
    Media::factory()->create(['title' => 'Last Month', 'ratings_count' => 0, 'published_on' => now()->subDays(30)]);

    $perShelf = 2;
    $shelves = app(HomeShelves::class)->guestSafe($perShelf);

    expect($shelves->firstWhere('key', 'trending')->items)->toHaveCount(2)
        ->and($shelves->firstWhere('key', 'new-releases')->items->pluck('title')->all())->toBe(['Fresh Arrival', 'Last Month']);

    Cache::flush();
    Media::query()->where('ratings_count', 0)->delete();

    expect(app(HomeShelves::class)->guestSafe(10)->firstWhere('key', 'new-releases')->items)->toHaveCount(4);
});

test('the subject shelf holds the main classes that have stories, with their counts', function () {
    $this->seed(DeweySeeder::class);
    classified(Media::factory()->count(2)->create(), '813');
    classified(Media::factory()->create(), '510');

    $items = app(HomeShelves::class)->guestSafe()->firstWhere('key', 'explore-by-subject')->items;

    expect($items->pluck('code')->all())->toBe(['500', '800'])
        ->and($items->pluck('stories_count')->all())->toBe([1, 2]);
});

test('the shelves are kept for ten minutes as ids, so a cache hit never returns an incomplete class', function () {
    Media::factory()->count(2)->create();

    app(HomeShelves::class)->guestSafe();
    $cached = Cache::get('home:shelves:v1:20');

    expect($cached)->toBeArray()->and(json_encode($cached))->not->toBeFalse();

    Media::query()->delete();

    expect(app(HomeShelves::class)->guestSafe()->firstWhere('key', 'trending')->items)->toHaveCount(0);
});

test('the page ships a skeleton for every shelf, titled, the right height, and gradient only on trending', function () {
    Media::factory()->count(5)->create();

    $html = $this->actingAs(User::factory()->create())->get(route('home'))->getContent();

    foreach (['trending' => 'Trending Now', 'new-releases' => 'New Releases', 'explore-by-subject' => 'Explore by Subject'] as $key => $title) {
        expect(htmlQuery($html, '//section[@data-shelf-skeleton="'.$key.'"]//h2[normalize-space()="'.$title.'"]')->length)->toBe(1);
    }

    expect(htmlQuery($html, '//section[@data-shelf-skeleton="trending"][contains(@class,"from-featured-from")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//section[@data-shelf-skeleton]//li[@data-skeleton-tile]')->length)->toBeGreaterThan(8)
        ->and(htmlQuery($html, '//section[@data-shelf-skeleton="explore-by-subject"]//li[contains(@class,"h-40")]')->length)->toBeGreaterThan(3)
        ->and(htmlQuery($html, '//section[@data-shelf-skeleton="trending"]//li//div[contains(@class,"aspect-[2/3]")]')->length)->toBeGreaterThan(5);
});

test('the loaded shelves have tiles with a hover preview, a touch quick view and a see all link', function () {
    $this->seed(DeweySeeder::class);
    classified(ratedStories(6, ['published_on' => now()->subDays(3)]), '813');
    Livewire::withoutLazyLoading();

    $html = $this->actingAs(User::factory()->create())->get(route('home'))->getContent();

    expect(htmlQuery($html, '//section[@aria-labelledby="shelf-trending"]//li//*[@x-data="storyPreview"]')->length)->toBe(6)
        ->and(htmlQuery($html, '//section[@aria-labelledby="shelf-trending"]//li//button[contains(.,"Quick view")]')->length)->toBe(6)
        ->and(htmlQuery($html, '//section[@aria-labelledby="shelf-trending"]//a[@href="'.route('discover').'"][contains(@aria-label,"See all in Trending Now")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//section[@aria-labelledby="shelf-new-releases"]//a[@href="'.route('discover').'"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//section[@aria-labelledby="shelf-explore-by-subject"]//a[@href="'.route('explore.index').'"][contains(@aria-label,"See all in Explore by Subject")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//section[@aria-labelledby="shelf-explore-by-subject"]//a[@href="'.route('explore.show', '800').'"]')->length)->toBe(1);
});

test('a shelf with nothing in it renders nothing at all', function () {
    Media::factory()->count(2)->create();
    Livewire::withoutLazyLoading();

    $html = $this->actingAs(User::factory()->create())->get(route('home'))->getContent();

    expect($html)->toContain('Trending Now')->not->toContain('Explore by Subject');
});

test('hero renders eyebrow, one h1, blurb, the CTA, the next three cards and the 01 counter', function () {
    ratedStories(6);
    $first = Media::query()->where('title', 'Rated Story 1')->first();

    $html = $this->actingAs(User::factory()->create())->get(route('home'))->getContent();

    expect(htmlQuery($html, '//section[@aria-roledescription="carousel"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//article[@aria-label="1 of 5"]//h1')->length)->toBe(1)
        ->and(htmlQuery($html, '//article[@aria-label="1 of 5"]//p[contains(@class,"uppercase")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//article[@aria-label="1 of 5"]//p[contains(@class,"line-clamp-2")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//article[@aria-label="1 of 5"]//a[@href="'.route('stories.show', $first->slug).'"][normalize-space()="View story"]')->length)->toBe(1)
        ->and($html)->not->toContain('Add to Library')
        ->and(htmlQuery($html, '//*[@x-text="String(current + 1).padStart(2, \'0\')"]')->item(0)->textContent)->toBe('01')
        ->and(htmlQuery($html, '//img[@data-backdrop="0"][@fetchpriority="high"]')->length)->toBe(1);
});

test('the hero is the five most trending stories that have a cover, with the everyday sized ctas', function () {
    ratedStories(6);
    Media::factory()->create(['title' => 'No Cover Hit', 'ratings_count' => 5000, 'cover_url' => null]);

    $html = $this->actingAs(User::factory()->create())->get(route('home'))->getContent();

    expect(htmlQuery($html, '//article[@role="group"]')->length)->toBe(5)
        ->and(htmlQuery($html, '//article[@aria-label="1 of 5"]//a[@data-flux-button][contains(@class,"text-body!")]')->length)->toBe(1)
        ->and($html)->not->toContain('No Cover Hit');
});

test('the search panel sits under the hero and opens the search page on Enter', function () {
    ratedStories(3);

    $html = $this->actingAs(User::factory()->create())->get(route('home'))->getContent();

    expect(strpos($html, 'aria-roledescription="carousel"'))->toBeLessThan(strpos($html, '<search'))
        ->and(htmlQuery($html, '//main//search//a[normalize-space()="Browse Discover"][@href="'.route('discover').'"]')->length)->toBe(1)
        ->and($html)->toContain('Livewire.navigate(')->toContain('q.trim().length >= 2');
});

test('the hero ids ride in the cached shelf pick, so a second home render costs at least a query less', function () {
    ratedStories(8);
    $this->actingAs(User::factory()->create());

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('home'))->assertOk();

        return count(DB::getQueryLog());
    };

    $first = $count();
    $second = $count();

    expect(Cache::get('home:shelves:v1:20')['hero'])->toHaveCount(5)->each->toBeInt()
        ->and($second)->toBeLessThanOrEqual($first - 1);
});
