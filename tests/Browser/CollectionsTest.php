<?php

use App\Actions\Library\LogProgress;
use App\Models\Collection;
use App\Models\Media;
use App\Models\ReadingProgress;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->stories = Media::factory()->manga()->count(4)->sequence(fn ($sequence) => [
        'title' => ['Alpha Tale', 'Beta Tale', 'Gamma Tale', 'Delta Tale'][$sequence->index],
        'cover_url' => '/favicon.svg',
    ])->create();

    $this->collection = collectionOf($this->user, $this->stories->all(), ['name' => 'Rainy Day']);
    $this->pick = collectionOf(User::factory()->admin()->create(), $this->stories->take(3)->all(), ['name' => 'Spooky Season', 'is_editorial' => true, 'description' => 'Read with the lights on.']);

    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812]];

    // The titles of the rows of the owner's list, in order, and what has the keyboard focus.
    $this->rows = 'Array.from(document.querySelectorAll("[data-view=rows] > li")).map((li) => li.querySelector("p a").textContent.trim()).join("|")';
    $this->focused = 'document.activeElement.getAttribute("aria-label")';
});

test('the collections pages pass the shared checks on desktop and on a phone', function (string $url) {
    foreach ([$this->desktop, $this->phone] as $viewport) {
        $page = visit($url === 'index' ? route('collections.index') : ($url === 'show' ? route('collections.show', $this->collection->slug) : route('collections.show', ['slug' => $this->pick->slug, 'editors' => 1])), $viewport)->wait(1);
        scrolledThrough($page);

        $page->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
    }
})->with(['the index' => 'index', 'a collection of your own' => 'show', 'a staff pick' => 'pick']);

test('nothing spills sideways on a phone, and every control on the collection page is a 44px target', function () {
    visit(route('collections.show', $this->collection->slug), $this->phone + ['reducedMotion' => 'reduce'])->wait(1)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertScript('Array.from(document.querySelectorAll("[data-view=rows] button, #add-stories input, [data-view=rows] li p a")).every((el) => el.getBoundingClientRect().height >= 44)', true);

    visit(route('collections.index'), $this->phone + ['reducedMotion' => 'reduce'])->wait(1)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertScript('Array.from(document.querySelectorAll("main ul > li > a")).every((el) => el.getBoundingClientRect().height >= 44)', true);
});

test('a collection is reordered from the keyboard, with the focus kept on the button that was pressed', function () {
    $page = visit(route('collections.show', $this->collection->slug), $this->desktop)->wait(1);

    $page->assertScript($this->rows, 'Alpha Tale|Beta Tale|Gamma Tale|Delta Tale')
        ->keys('[aria-label="Move Beta Tale up"]', 'Enter')->wait(1)
        ->assertScript($this->rows, 'Beta Tale|Alpha Tale|Gamma Tale|Delta Tale')
        ->assertScript('document.querySelector("[data-test=moved]").textContent', 'Beta Tale moved to position 1 of 4.')
        // Beta is first now and has no up button, so the focus went to its down button.
        ->assertScript($this->focused, 'Move Beta Tale down')
        ->keys('[aria-label="Move Alpha Tale down"]', 'Enter')->wait(1)
        ->assertScript($this->rows, 'Beta Tale|Gamma Tale|Alpha Tale|Delta Tale')
        ->assertScript($this->focused, 'Move Alpha Tale down')
        ->assertNoJavaScriptErrors();

    expect($this->collection->items()->orderBy('position')->with('media')->get()->pluck('media.title')->all())->toBe(['Beta Tale', 'Gamma Tale', 'Alpha Tale', 'Delta Tale']);
});

test('removing a story and adding one from the library work on the page', function () {
    $this->user->library()->create(['media_id' => Media::factory()->create(['title' => 'Epsilon Tale', 'cover_url' => '/favicon.svg'])->id]);

    visit(route('collections.show', $this->collection->slug), $this->desktop)->wait(1)
        ->click('[aria-label="Remove Beta Tale from this collection"]')->wait(1)
        ->assertScript($this->rows, 'Alpha Tale|Gamma Tale|Delta Tale')
        ->assertSee('From your library')
        ->click('#add-stories button:has-text("Add")')->wait(1)
        ->assertScript($this->rows, 'Alpha Tale|Gamma Tale|Delta Tale|Epsilon Tale')
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();

    expect($this->collection->items()->count())->toBe(4);
});

test('dragging a story by its handle reorders the collection', function () {
    $page = visit(route('collections.show', $this->collection->slug), $this->desktop)->wait(1);

    $page->drag('[data-view=rows] > li:nth-child(1) [wire\\:sort\\:handle]', '[data-view=rows] > li:nth-child(3) [wire\\:sort\\:handle]')->wait(1.5);

    expect($this->collection->items()->orderBy('position')->with('media')->get()->pluck('media.title')->first())->not->toBe('Alpha Tale');
});

test('the collection menu on a story adds and removes it, and a new collection takes it in', function () {
    $story = $this->stories->first();
    $page = visit(route('stories.show', $story->slug), $this->desktop)->wait(1);

    $page->click('[data-test=collection-menu]')->wait(0.5)
        ->assertScript('document.querySelectorAll("ui-menu-checkbox[checked]").length', 1) // Rainy Day holds it already
        ->click('ui-menu-checkbox:has-text("Rainy Day")')->wait(1)
        ->assertSee('was removed from Rainy Day');

    expect($this->collection->items()->where('media_id', $story->id)->exists())->toBeFalse();

    $page->click('[data-test=collection-menu]')->wait(0.5)
        ->click('ui-menu button:has-text("New collection")')->wait(0.8)
        ->assertNoAccessibilityIssues()
        ->type('[role=dialog][open] input, dialog[open] input', 'Cozy Corner')
        ->click('dialog[open] button[type=submit]')->wait(1.5)
        ->assertSee('was added to Cozy Corner')
        ->assertNoJavaScriptErrors();

    $cozy = Collection::where('name', 'Cozy Corner')->sole();
    expect($cozy->items()->sole()->media_id)->toBe($story->id)->and($cozy->user_id)->toBe($this->user->id);
});

test('the progress form logs where the reader is, shows it on the button, and passes the audit open', function (array $viewport) {
    $story = $this->stories->first();
    $page = visit(route('stories.show', $story->slug), $viewport)->wait(1);

    $page->click('[data-test=log-progress]')->wait(0.8)
        ->assertSee('Log your progress')
        ->assertNoAccessibilityIssues()
        ->type('dialog[open] input[name=percent]', '42.5')
        ->click('dialog[open] button[type=submit]')->wait(1.5)
        ->assertSee('Progress saved')
        ->assertScript('document.querySelector("[data-test=log-progress]").textContent.replace(/\s+/g, " ").trim()', '42.5% read. Update your progress')
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();

    expect((float) ReadingProgress::sole()->percent)->toBe(42.5);
})->with(['desktop' => fn () => ['viewport' => ['width' => 1440, 'height' => 900]], 'phone' => fn () => ['viewport' => ['width' => 375, 'height' => 812]]]);

test('continue reading is the first shelf on home, with the progress bar and where the reader was', function () {
    app(LogProgress::class)->handle($this->user, $this->stories->first(), 42, chapter: '7');

    $page = visit(route('home'), $this->desktop + ['reducedMotion' => 'reduce'])->wait(1.5);
    scrolledThrough($page);

    $page->assertSee('Continue Reading')->assertSee('Chapter 7')
        ->assertScript('document.querySelector("[aria-labelledby=shelf-continue-reading] [role=progressbar]").getAttribute("aria-valuenow")', '42')
        ->assertScript('document.querySelector("main section[aria-labelledby^=shelf-]").getAttribute("aria-labelledby")', 'shelf-continue-reading')
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
});
