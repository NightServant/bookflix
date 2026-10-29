<?php

use App\Enums\ReadingStatus;
use App\Models\Genre;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * A reader with four stories, added in this order (so newest first is D, C, B, A):
 * A manga reading and a favorite, B novel completed, C manga want to read, D novel abandoned.
 * With `classified`, the novels stand on the Literature shelf (813) and the manga on Arts & Play (741.52).
 */
function libraryFixture(bool $classified = false): User
{
    $user = User::factory()->create();
    $rows = [
        ['A', 'manga', ReadingStatus::Reading, true],
        ['B', 'novel', ReadingStatus::Completed, false],
        ['C', 'manga', ReadingStatus::WantToRead, false],
        ['D', 'novel', ReadingStatus::Abandoned, false],
    ];

    if ($classified) {
        (new DeweySeeder)->run();
    }

    foreach ($rows as $index => [$title, $type, $status, $favorite]) {
        $media = Media::factory()->ofType($type)->create(['title' => $title]);
        $classified && classified($media, $type === 'novel' ? '813' : '741.52');

        LibraryEntry::factory()->for($user)->for($media)->status($status)->when($favorite, fn ($factory) => $factory->favorite())->create(['created_at' => now()->subDays(10 - $index)]);
    }

    return $user;
}

/**
 * The titles on the page, in order.
 *
 * @return list<string>
 */
function libraryTitles(Testable $page): array
{
    return $page->instance()->entries->map(fn (LibraryEntry $entry): string => $entry->media->title)->all();
}

test('the library is for signed in, verified readers', function () {
    $this->get(route('library.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->get(route('library.index'))->assertRedirect(route('verification.notice'));
    $this->actingAs(User::factory()->create())->get(route('library.index'))->assertOk()->assertSeeLivewire('pages::library.index');
});

test('each filter narrows the library', function (array $set, array $expected) {
    $this->actingAs(libraryFixture());
    $page = Livewire::test('pages::library.index');

    foreach ($set as $key => $value) {
        $page->set($key, $value);
    }

    expect(libraryTitles($page))->toEqualCanonicalizing($expected);
})->with([
    'status' => [['status' => 'completed'], ['B']],
    'type' => [['type' => 'manga'], ['A', 'C']],
    'favorites' => [['favorites' => true], ['A']],
    'combined' => [['type' => 'novel', 'status' => 'abandoned'], ['D']],
    'filters that exclude each other' => [['type' => 'manga', 'status' => 'completed'], []],
]);

test('the newest additions come first, and the title sort is alphabetical', function () {
    $this->actingAs(libraryFixture());

    expect(libraryTitles(Livewire::test('pages::library.index')))->toBe(['D', 'C', 'B', 'A'])
        ->and(libraryTitles(Livewire::test('pages::library.index')->set('sort', 'title')))->toBe(['A', 'B', 'C', 'D']);
});

test('the title sort ignores case', function () {
    $user = User::factory()->create();

    foreach (['banana', 'Apple', 'cherry'] as $title) {
        LibraryEntry::factory()->for($user)->for(Media::factory()->create(['title' => $title]))->create();
    }

    expect(libraryTitles(Livewire::actingAs($user)->test('pages::library.index')->set('sort', 'title')))->toBe(['Apple', 'banana', 'cherry']);
});

test('only the owners entries appear', function () {
    $this->actingAs(libraryFixture());
    LibraryEntry::factory()->for(User::factory()->create())->create();

    expect(Livewire::test('pages::library.index')->instance()->entries)->toHaveCount(4);
});

test('invalid values in the url are ignored, not errors', function () {
    $this->actingAs(libraryFixture());

    $page = Livewire::withQueryParams(['status' => 'bogus', 'type' => 'no-such-type', 'sort' => 'drop table', 'view' => 'cinema'])
        ->test('pages::library.index')
        ->assertHasNoErrors();

    expect(libraryTitles($page))->toBe(['D', 'C', 'B', 'A']);

    $page->set('status', 'bogus')->set('sort', 'drop table')->assertHasNoErrors();
    expect(libraryTitles($page))->toBe(['D', 'C', 'B', 'A']);
});

test('the grid is the default view and the list shows compact rows with the status and when it was added', function () {
    $this->actingAs(libraryFixture());

    $grid = Livewire::test('pages::library.index')->html();
    expect(htmlQuery($grid, '//ul[@data-view="grid"]/li')->length)->toBe(4)
        ->and(htmlQuery($grid, '//ul[@data-view="list"]')->length)->toBe(0);

    $list = Livewire::test('pages::library.index')->set('view', 'list')->html();
    expect(htmlQuery($list, '//ul[@data-view="list"]/li//article')->length)->toBe(4)
        ->and(htmlQuery($list, '//ul[@data-view="grid"]')->length)->toBe(0)
        ->and(htmlQuery($list, '//ul[@data-view="list"]/li[1]//article[contains(.,"Abandoned")]')->length)->toBe(1)
        ->and(htmlQuery($list, '//ul[@data-view="list"]//article//time')->length)->toBe(4);
});

test('the view lives in the url, so a reload and a shared link keep it', function () {
    $this->actingAs(libraryFixture());

    Livewire::withQueryParams(['view' => 'list'])->test('pages::library.index')->assertSet('view', 'list')->assertSeeHtml('data-view="list"');
    Livewire::withQueryParams(['view' => 'shelves'])->test('pages::library.index')->assertSet('view', 'shelves');

    $attributes = collect((new ReflectionClass(Livewire::test('pages::library.index')->instance()))->getProperties())
        ->mapWithKeys(fn (ReflectionProperty $property): array => [$property->getName() => count($property->getAttributes(Url::class))]);

    expect($attributes->only(['status', 'type', 'favorites', 'sort', 'view'])->all())->toBe(['status' => 1, 'type' => 1, 'favorites' => 1, 'sort' => 1, 'view' => 1]);
});

test('the shelves view groups the library by classification', function () {
    $this->actingAs(libraryFixture(classified: true));

    $html = Livewire::test('pages::library.index')->set('view', 'shelves')->assertSee('Literature')->assertSee('Arts')->html();

    expect(htmlQuery($html, '//section[@aria-labelledby]//li//a[contains(@href,"/stories/")]')->length)->toBe(4)
        ->and(htmlQuery($html, '//ul[@data-view="grid"]')->length)->toBe(0)
        ->and(htmlQuery($html, '//main//a[contains(@href,"/explore/")][contains(.,"See all")]')->length)->toBe(0);
});

test('a story with no public class still has a shelf of its own in the shelves view, and none is lost', function () {
    $user = libraryFixture(classified: true);
    LibraryEntry::factory()->for($user)->for(Media::factory()->create(['title' => 'Unfiled Tale']))->create();
    $this->actingAs($user);

    $html = Livewire::test('pages::library.index')->set('view', 'shelves')->assertSee('Not Yet Shelved')->assertSee('Unfiled Tale')->html();

    expect(htmlQuery($html, '//section[@aria-labelledby]//li//a[contains(@href,"/stories/")]')->length)->toBe(5);
});

test('the empty library says so and points the way', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::library.index')
        ->assertSee('Your library is empty')
        ->assertSeeHtml(route('discover'))
        ->assertDontSee('No stories match');
});

test('filters that match nothing say so and offer to clear them', function () {
    $this->actingAs(libraryFixture());

    $page = Livewire::test('pages::library.index')->set('status', 'paused')->assertSee('No stories match')->assertDontSee('Your library is empty');

    $page->call('clearFilters')->assertSet('status', null)->assertDontSee('No stories match');
    expect(libraryTitles($page))->toHaveCount(4);
});

test('every active filter is a chip that can be removed, and there is a way to clear all', function () {
    $this->actingAs(libraryFixture());
    $page = Livewire::test('pages::library.index')->set('status', 'reading')->set('type', 'manga')->set('favorites', true);

    $chips = collect(htmlQuery($page->html(), '//ul[@aria-label="Active filters"]/li[@data-chip]'))->map(fn (DOMElement $chip): string => trim(preg_replace('/\s+/', ' ', $chip->textContent)))->all();
    expect($chips)->toBe(['Reading', 'Manga', 'Favorites']);

    expect(htmlQuery($page->html(), '//ul[@aria-label="Active filters"]//button[@aria-label="Remove filter: Reading"]')->length)->toBe(1);

    $page->call('removeFilter', 'type')->assertSet('type', null)->assertSet('status', 'reading')->assertSet('favorites', true);
    $page->call('removeFilter', 'favorites')->assertSet('favorites', false);
    $page->call('removeFilter', 'status')->assertSet('status', null);
    $page->call('removeFilter', 'sort')->assertHasNoErrors();

    expect(htmlQuery($page->html(), '//ul[@aria-label="Active filters"]')->length)->toBe(0);
});

test('the status tabs count the library by status, and the counts follow the format and favorite filters', function () {
    $this->actingAs(libraryFixture());
    $page = Livewire::test('pages::library.index');

    $counts = fn () => collect(htmlQuery($page->html(), '//nav[@aria-label="Status"]//button'))->mapWithKeys(function (DOMElement $button): array {
        preg_match('/^(.*?)\s+(\d+)$/', trim(preg_replace('/\s+/', ' ', $button->textContent)), $match);

        return [$match[1] => (int) $match[2]];
    })->all();

    expect($counts())->toBe(['All' => 4, 'Want to read' => 1, 'Reading' => 1, 'Completed' => 1, 'Paused' => 0, 'Abandoned' => 1]);

    $page->set('type', 'manga');
    expect($counts())->toBe(['All' => 2, 'Want to read' => 1, 'Reading' => 1, 'Completed' => 0, 'Paused' => 0, 'Abandoned' => 0]);

    $page->set('status', 'reading');
    expect($counts()['All'])->toBe(2)->and(htmlQuery($page->html(), '//nav[@aria-label="Status"]//button[@aria-pressed="true"]')->length)->toBe(1);
});

test('a library over a page is paged, and the pager says where you are', function () {
    $user = User::factory()->create();
    LibraryEntry::factory()->count(30)->for($user)->create();
    $this->actingAs($user);

    $page = Livewire::test('pages::library.index');
    expect($page->instance()->entries)->toHaveCount(24)->and(htmlQuery($page->html(), '//nav[@data-pager]')->length)->toBe(1);

    $page->call('nextPage');
    expect($page->instance()->entries)->toHaveCount(6);
});

test('a filter change goes back to the first page', function () {
    $user = User::factory()->create();
    LibraryEntry::factory()->count(30)->for($user)->create();
    $this->actingAs($user);

    $page = Livewire::test('pages::library.index')->call('nextPage')->set('sort', 'title');

    expect($page->instance()->entries)->toHaveCount(24);
});

test('a story behind the mature gate stays out of the library, its counts and its shelves until the reader opts in', function () {
    $user = libraryFixture(classified: true);
    $mature = Media::factory()->novel()->create(['title' => 'Erotic Tale']);
    $mature->genres()->attach(Genre::factory()->mature()->create(['slug' => 'erotica']));
    classified($mature, '813');
    LibraryEntry::factory()->for($user)->for($mature)->status(ReadingStatus::Reading)->create();

    $gated = Livewire::actingAs($user)->test('pages::library.index');
    expect(libraryTitles($gated))->not->toContain('Erotic Tale')->toHaveCount(4)
        ->and($gated->instance()->statusCounts['reading'])->toBe(1)
        ->and($gated->instance()->statusCounts->sum())->toBe(4)
        ->and($gated->set('view', 'shelves')->assertDontSee('Erotic Tale')->html())->not->toContain('Erotic Tale');

    // The entry was never deleted: it is back for a reader who opts in and confirms their age.
    $user->profile()->updateOrCreate([], ['show_mature' => true, 'adult_confirmed_at' => now()]);
    $open = Livewire::actingAs($user->fresh())->test('pages::library.index');
    expect(libraryTitles($open))->toContain('Erotic Tale')->toHaveCount(5)
        ->and($open->instance()->statusCounts['reading'])->toBe(2);
});

test('a mature tagged entry is shown to a reader created with the opt in', function () {
    $user = User::factory()->showsMature()->create();
    $mature = Media::factory()->create(['title' => 'Erotic Tale']);
    $mature->genres()->attach(Genre::factory()->mature()->create());
    LibraryEntry::factory()->for($user)->for($mature)->create();

    expect(libraryTitles(Livewire::actingAs($user)->test('pages::library.index')))->toBe(['Erotic Tale']);
});

test('an adult rated entry is gated too, and a deleted or merged away one is not listed', function () {
    $user = User::factory()->create();
    $adult = Media::factory()->create(['title' => 'Adult Tale', 'age_rating' => 'adult']);
    $deleted = Media::factory()->create(['title' => 'Deleted Tale']);
    $merged = Media::factory()->create(['title' => 'Merged Tale', 'merged_into_id' => Media::factory()->create()->id]);
    $plain = Media::factory()->create(['title' => 'Plain Tale']);

    foreach ([$adult, $deleted, $merged, $plain] as $media) {
        LibraryEntry::factory()->for($user)->for($media)->create();
    }

    $deleted->delete();

    expect(libraryTitles(Livewire::actingAs($user)->test('pages::library.index')))->toBe(['Plain Tale']);
});

test('the view-library gate honours privacy and deactivation', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    expect(Gate::forUser($other)->allows('view-library', $owner))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view-library', $owner))->toBeTrue()
        ->and(Gate::forUser(null)->allows('view-library', $owner))->toBeFalse();

    $owner->profile()->updateOrCreate([], ['library_visibility' => 'public']);
    expect(Gate::forUser($other)->allows('view-library', $owner->refresh()))->toBeTrue()
        ->and(Gate::forUser(null)->allows('view-library', $owner))->toBeTrue();

    $owner->forceFill(['deactivated_at' => now()])->save();
    expect(Gate::forUser($other)->allows('view-library', $owner->refresh()))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view-library', $owner))->toBeTrue();
});

test('the library runs a bounded number of queries in every view', function (string $view, int $budget) {
    $user = User::factory()->create();
    (new DeweySeeder)->run();
    $media = Media::factory()->count(50)->create();
    classified($media, '813');
    $media->each(fn (Media $item) => LibraryEntry::factory()->for($user)->for($item)->create());
    $this->actingAs($user);
    $user->load('profile');

    DB::enableQueryLog();
    $html = Livewire::test('pages::library.index')->set('view', $view)->html();
    $queries = count(DB::getQueryLog());

    expect($queries)->toBeLessThanOrEqual($budget)->and($html)->toContain('wire:key');
})->with([
    'grid' => ['grid', 15],
    'list' => ['list', 18],
    'shelves' => ['shelves', 16],
]);

test('the page opens with a title and a guide, a status row, a format and sort control and the layout switch', function () {
    $html = $this->actingAs(libraryFixture())->get(route('library.index'))->assertOk()->getContent();

    expect(htmlQuery($html, '//main//h1')->length)->toBe(1)
        ->and(trim(htmlQuery($html, '//main//h1')->item(0)->textContent))->toBe('Library')
        ->and(htmlQuery($html, '//main//h1/following::p[1]')->item(0)->textContent)->not->toBeEmpty()
        ->and(htmlQuery($html, '//nav[@aria-label="Status"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//select[@id="library-type"][contains(@class,"h-11")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//label[@for="library-type"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//select[@id="library-sort"][contains(@class,"h-11")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//label[@for="library-sort"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@role="radiogroup" or @*[name()="x-radio"]]//button[@*[name()="x-radio:option"]]')->length)->toBe(3)
        ->and(htmlQuery($html, '//button[@aria-pressed][contains(.,"Favorites")]')->length)->toBe(1);
});

test('the format list holds the formats in the library and no others', function () {
    $this->actingAs(libraryFixture());
    Media::factory()->graphicNovel()->create();

    $html = Livewire::test('pages::library.index')->html();

    expect(collect(htmlQuery($html, '//select[@id="library-type"]/option'))->map(fn (DOMElement $option): string => trim($option->textContent))->all())
        ->toBe(['All formats', 'Manga', 'Novel']);
});

test('the result count says how many stories match', function () {
    $this->actingAs(libraryFixture());

    $page = Livewire::test('pages::library.index');
    expect(trim(htmlQuery($page->html(), '//*[@data-test="result-count"]')->item(0)->textContent))->toBe('4 stories');

    $page->set('status', 'reading');
    expect(trim(htmlQuery($page->html(), '//*[@data-test="result-count"]')->item(0)->textContent))->toBe('1 story');
});

test('a cover links to the story and the library page never lazy loads', function () {
    $user = libraryFixture(classified: true);
    $this->actingAs($user);

    foreach (['grid', 'list', 'shelves'] as $view) {
        $html = Livewire::test('pages::library.index')->set('view', $view)->html();

        expect($html)->toContain(route('stories.show', $user->library()->with('media')->first()->media->slug));
    }
});

test('a crafted url with lists where text belongs still opens the library', function () {
    $this->actingAs(libraryFixture());

    $this->get(route('library.index', ['status' => ['a'], 'type' => ['b'], 'sort' => ['c'], 'view' => ['d']]))->assertOk();
});
