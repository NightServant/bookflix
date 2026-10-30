<?php

use App\Models\ContentWarning;
use App\Models\Genre;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\MediaTypeSeeder;
use Database\Seeders\TaxonomySeeder;

/*
 * The genre selection and filter panel on Search and Discover, in a real browser: the tree (expand, select, tri-state, included subgenres), the
 * filter-the-list search, the content warnings that are hidden, chips, Clear all, the URL and the back button, the phone sheet with Apply, the
 * keyboard, the audit and the page width.
 */

/**
 * A JavaScript expression for the checkbox of one genre (family or subgenre) in the panel.
 */
function box(string $slug): string
{
    return 'document.querySelector("[data-genre=\''.$slug.'\']")';
}

beforeEach(function () {
    $this->seed([MediaTypeSeeder::class, TaxonomySeeder::class]);
    $this->actingAs(User::factory()->create());

    $story = function (string $title, string $genre, array $attributes = []): Media {
        $media = Media::factory()->manga()->create(['title' => $title, 'search_text' => strtolower($title), ...$attributes]);
        $media->genres()->attach(Genre::where('slug', $genre)->first());

        return $media;
    };

    $story('Dune Isekai', 'isekai');
    $story('Dune Litrpg', 'litrpg');
    $story('Dune Slowburn', 'slow-burn');
    $story('Dune Gory', 'isekai')->warnings()->attach(ContentWarning::where('slug', 'gore')->first(), ['status' => 'present']);
    $story('Dune Unknown', 'isekai')->warnings()->attach(ContentWarning::where('slug', 'gore')->first(), ['status' => 'unknown']);

    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 390, 'height' => 844]];
    $this->side = 'aside[aria-labelledby=search-filters-title]';
    $this->count = 'document.querySelector("[data-test=result-count]").textContent.trim()';
    $this->chips = 'Array.from(document.querySelectorAll("ul[aria-label=\'Active filters\'] ul > li > span:first-child")).map((span) => span.textContent.trim()).join("|")';
    $this->query = 'decodeURIComponent(location.search)';
    $this->titles = 'Array.from(document.querySelectorAll("[data-view] li a span.line-clamp-2")).map((title) => title.textContent.trim()).sort().join("|")';
});

test('expanding a family and choosing a subgenre shows its chip and narrows the results', function () {
    $page = visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1);

    $page->assertScript($this->count, '5 stories')
        ->assertScript('document.querySelector("[data-family-toggle][data-slug=fantasy]").getAttribute("aria-expanded")', 'false')
        ->click('[data-family-toggle][data-slug=fantasy]')
        ->assertScript('document.querySelector("[data-family-toggle][data-slug=fantasy]").getAttribute("aria-expanded")', 'true')
        ->click('[data-genre=isekai]')->wait(1.5)
        ->assertScript($this->chips, 'Isekai')
        ->assertScript($this->count, '3 stories')
        ->assertScript($this->titles, 'Dune Gory|Dune Isekai|Dune Unknown')
        // selecting a subgenre does not select its siblings
        ->assertScript(box('litrpg').'.checked', false)
        ->assertNoJavaScriptErrors();
});

test('the parent is mixed while some subgenres are chosen, and a chosen parent shows its subgenres as included', function () {
    $page = visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1);

    $page->click('[data-family-toggle][data-slug=fantasy]')
        ->click('[data-genre=isekai]')->wait(0.6)
        ->assertScript(box('fantasy').'.indeterminate', true)
        ->assertScript(box('fantasy').'.checked', false)
        ->click('[data-genre=fantasy]')->wait(1.5)
        ->assertScript(box('fantasy').'.indeterminate', false)
        ->assertScript(box('fantasy').'.checked', true)
        // the subgenres are shown included: checked, marked, and not choosable one by one
        ->assertScript(box('isekai').'.checked', true)
        ->assertScript(box('isekai').'.hasAttribute("data-implied")', true)
        ->assertScript(box('isekai').'.getAttribute("aria-disabled")', 'true')
        ->assertScript($this->chips, 'Fantasy')
        ->assertScript($this->count, '4 stories')
        ->script(box('isekai').'.click()');

    $page->wait(0.6)
        ->assertScript(box('fantasy').'.checked', true)
        ->assertScript(box('isekai').'.checked', true)
        ->assertScript($this->chips, 'Fantasy')
        // clearing the parent clears what it included
        ->click('[data-genre=fantasy]')->wait(1.5)
        ->assertScript(box('isekai').'.checked', false)
        ->assertScript($this->chips, '')
        ->assertScript($this->count, '5 stories');
});

test('choosing every subgenre of a family turns them into the family', function () {
    $page = visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1);
    $slugs = Genre::where('slug', 'fantasy')->first()->children()->pluck('slug')->merge(Genre::where('slug', 'fantasy')->first()->secondaryChildren()->pluck('slug'))->unique()->values()->all();

    $page->script('Alpine.$data(document.querySelector("[x-data^=filterPanel]")).draft.genres = '.json_encode(array_slice($slugs, 0, -1)));
    $page->click('[data-family-toggle][data-slug=fantasy]')->wait(0.3)
        ->assertScript(box('fantasy').'.indeterminate', true)
        ->script('document.querySelector("[data-genre=\''.end($slugs).'\']").click()');
    $page->wait(1.5)
        ->assertScript(box('fantasy').'.checked', true)
        ->assertScript($this->chips, 'Fantasy');
});

test('the taxonomy search finds a subgenre and shows its family path', function () {
    $page = visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1);

    $page->type('#fp-search-genres', 'isek')->wait(0.5)
        ->assertScript('document.querySelectorAll("[data-family-toggle]").length', 0)
        ->assertScript('document.querySelector("[data-genre=isekai]") !== null', true)
        ->assertScript('document.querySelector("[data-genre=isekai]").closest("label").textContent.replace(/\s+/g, " ")', ' Isekai Fantasy · Progression and Eastern fantasy 3 ')
        ->assertScript('document.querySelector("#fp-search-genres").closest("section").querySelector("[role=status]").textContent', '2 matches')
        ->click('[data-genre=isekai]')->wait(1.5)
        ->assertScript($this->chips, 'Isekai')
        ->type('#fp-search-genres', 'zzzzzz')->wait(0.4)
        ->assertSee('No genres match')
        ->clear('#fp-search-genres')->wait(0.4)
        ->assertScript('document.querySelectorAll("[data-family-toggle]").length', 16);
});

test('a content warning is hidden, never required: the chip says Hide, and works with an unknown status stay', function () {
    $page = visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1);

    $page->click('button[aria-controls=fp-warnings]')->wait(0.3)
        ->assertSee('Hide works with:')
        ->assertSee('Works whose warning status is unknown are not hidden: unknown does not mean absent.')
        ->click('[data-filter=excludeWarnings][value=gore]')->wait(1.5)
        ->assertScript($this->chips, 'Hide: Gore')
        ->assertScript($this->titles, 'Dune Isekai|Dune Litrpg|Dune Slowburn|Dune Unknown')
        ->assertScript('document.querySelector("button[aria-controls=fp-warnings]").textContent.replace(/\s+/g, " ")', ' Content warnings 1 chosen ');
});

test('the reader rating offers stars on the five point scale, and an old link from the ten point scale means the same stars', function () {
    Media::factory()->manga()->create(['title' => 'Dune Beloved', 'search_text' => 'dune beloved', 'average_rating' => '4.60']);
    $options = 'Array.from(document.querySelectorAll("#fp-rating option")).map((option) => option.textContent.trim()).join("|")';

    $page = visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1);

    $page->click('button[aria-controls=fp-more]')->wait(0.3)
        ->assertScript($options, 'Any rating|3+ stars|3.5+ stars|4+ stars|4.5+ stars')
        ->select('#fp-rating', '4')->wait(1.5)
        ->assertScript($this->chips, '4+ stars')
        ->assertScript($this->titles, 'Dune Beloved')
        ->assertScript($this->query.'.includes("filters[minRating]=4")', true);

    $legacy = visit(route('search', ['q' => 'dune', 'filters' => ['minRating' => '9']]), $this->desktop)->wait(1.5);

    $legacy->assertScript($this->chips, '4.5+ stars')
        ->assertScript($this->titles, 'Dune Beloved')
        ->assertScript('document.querySelector("#fp-rating").value', '4.5')
        ->assertNoJavaScriptErrors();
});

test('Clear all removes every chip and every choice', function () {
    $page = visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1);

    $page->click('[data-family-toggle][data-slug=fantasy]')
        ->click('[data-genre=isekai]')
        ->click('button[aria-controls=fp-warnings]')->wait(0.3)
        ->click('[data-filter=excludeWarnings][value=gore]')->wait(1.5)
        ->assertScript($this->chips, 'Isekai|Hide: Gore')
        ->click($this->side.' button:has-text("Clear all")')->wait(1.5)
        ->assertScript($this->chips, '')
        ->assertScript($this->count, '5 stories')
        ->assertScript(box('isekai').'.checked', false)
        ->assertScript('document.querySelector("[data-filter=excludeWarnings][value=gore]").checked', false)
        ->assertScript($this->query.'.includes("filters")', false);
});

test('the choices are in the url, the back button restores the last ones, and a copied link restores all of them', function () {
    $page = visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1);

    $page->click('[data-family-toggle][data-slug=fantasy]')
        ->click('[data-genre=isekai]')->wait(1.5)
        ->assertScript($this->query.'.includes("filters[genres][0]=isekai")', true)
        ->click('button[aria-controls=fp-warnings]')->wait(0.3)
        ->click('[data-filter=excludeWarnings][value=gore]')->wait(1.5)
        ->assertScript($this->query.'.includes("filters[excludeWarnings][0]=gore")', true)
        ->script('history.back()');

    $page->wait(1.5)
        ->assertScript($this->chips, 'Isekai')
        ->assertScript('document.querySelector("[data-filter=excludeWarnings][value=gore]").checked', false)
        ->script('history.back()');

    $page->wait(1.5)->assertScript($this->chips, '')->assertScript(box('isekai').'.checked', false);

    $link = route('search', ['q' => 'dune', 'filters' => ['genres' => ['litrpg', 'slow-burn'], 'excludeWarnings' => ['gore']]]);
    $restored = visit($link, $this->desktop)->wait(1.5);

    $restored->assertScript($this->chips, 'LitRPG|Slow Burn|Hide: Gore')
        ->assertScript($this->titles, 'Dune Litrpg|Dune Slowburn')
        ->assertScript(box('litrpg').'.checked', true)
        ->assertScript('document.querySelector("[data-filter=excludeWarnings][value=gore]").checked', true)
        ->assertScript('document.querySelector("button[aria-controls=fp-warnings]").getAttribute("aria-expanded")', 'true');
});

test('removing a chip unchecks its box', function () {
    $page = visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1);

    $page->click('[data-family-toggle][data-slug=fantasy]')
        ->click('[data-genre=isekai]')->wait(1.5)
        ->click('[aria-label="Remove filter Isekai"]')->wait(1.5)
        ->assertScript($this->chips, '')
        ->assertScript(box('isekai').'.checked', false)
        ->assertScript($this->count, '5 stories');
});

test('on a phone nothing is applied until Apply, closing drops the draft, and the choices survive the round trip', function () {
    $isOpen = 'document.querySelector("dialog").open';
    $page = visit(route('search', ['q' => 'dune']), $this->phone)->wait(1);

    // the sidebar copy is not built below lg: the panel exists once, in the sheet
    $page->assertScript('document.querySelectorAll("aside [data-genre]").length', 0)
        ->assertScript('document.querySelectorAll("[data-genre]").length', 16)
        ->click('[data-flux-modal-trigger] button')->wait(0.8)
        ->assertScript($isOpen, true)
        ->assertScript('document.querySelectorAll("dialog [data-family-toggle]").length', 16)
        ->click('dialog [data-family-toggle][data-slug=fantasy]')
        ->click('dialog [data-genre=isekai]')->wait(1.5)
        ->assertScript($isOpen, true)
        ->assertScript($this->chips, '')
        ->assertScript($this->count, '5 stories')
        ->assertScript($this->query.'.includes("filters")', false)
        ->click('dialog button:has-text("Apply filters")')->wait(1.5)
        ->assertScript($isOpen, false)
        ->assertScript($this->chips, 'Isekai')
        ->assertScript($this->count, '3 stories')
        ->assertScript($this->query.'.includes("filters[genres][0]=isekai")', true)
        // the filters button says how many are applied
        ->assertScript('document.querySelector("[data-flux-modal-trigger]").textContent.replace(/\s+/g, " ")', ' Filters·1 applied ')
        // back in the sheet the choice is still there; a new one is dropped when the sheet is closed without Apply
        ->click('[data-flux-modal-trigger] button')->wait(0.8)
        ->assertScript(box('isekai').'.checked', true)
        ->click('dialog [data-genre=litrpg]')->wait(0.3)
        ->assertScript(box('litrpg').'.checked', true)
        ->click('dialog [aria-label="Close filters"]')->wait(0.8)
        ->assertScript($isOpen, false)
        ->assertScript($this->chips, 'Isekai')
        ->click('[data-flux-modal-trigger] button')->wait(0.8)
        ->assertScript(box('litrpg').'.checked', false)
        ->assertScript(box('isekai').'.checked', true)
        ->assertNoJavaScriptErrors();
});

test('on a phone Clear all in the sheet waits for Apply too', function () {
    $page = visit(route('search', ['q' => 'dune', 'filters' => ['genres' => ['isekai']]]), $this->phone)->wait(1.5);

    $page->assertScript($this->count, '3 stories')
        ->click('[data-flux-modal-trigger] button')->wait(0.8)
        ->click('dialog button:has-text("Clear all")')->wait(0.8)
        ->assertScript(box('isekai').'.checked', false)
        ->assertScript($this->count, '3 stories')
        ->click('dialog button:has-text("Apply filters")')->wait(1.5)
        ->assertScript($this->count, '5 stories')
        ->assertScript($this->chips, '');
});

test('the tree works from the keyboard: arrows move, right and left open and close, space chooses', function () {
    $page = visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1);
    $expanded = 'document.querySelector("[data-family-toggle][data-slug=fantasy]").getAttribute("aria-expanded")';

    $page->script('document.querySelector("[data-genre=fantasy]").focus()');
    $page->assertScript('document.activeElement.dataset.genre', 'fantasy')
        ->keys('[data-genre=fantasy]', 'ArrowDown')
        ->assertScript('document.activeElement.matches("[data-family-toggle][data-slug=fantasy]")', true) // the next stop is the family's own disclosure
        ->keys('[data-family-toggle][data-slug=fantasy]', 'ArrowRight')->wait(0.3)
        ->assertScript($expanded, 'true')
        ->keys('[data-family-toggle][data-slug=fantasy]', 'ArrowRight')
        ->assertScript('document.activeElement.dataset.genre', 'high-fantasy')
        ->keys('[data-genre=high-fantasy]', 'ArrowDown')
        ->assertScript('document.activeElement.dataset.genre', 'epic-fantasy')
        ->keys('[data-genre=epic-fantasy]', 'ArrowUp')
        ->assertScript('document.activeElement.dataset.genre', 'high-fantasy')
        ->keys('[data-genre=high-fantasy]', 'Space')->wait(1.5)
        ->assertScript($this->chips, 'High Fantasy')
        ->keys('[data-genre=high-fantasy]', 'ArrowLeft')
        ->assertScript('document.activeElement.matches("[data-family-toggle][data-slug=fantasy]")', true)
        ->keys('[data-family-toggle][data-slug=fantasy]', 'ArrowLeft')->wait(0.3)
        ->assertScript($expanded, 'false')
        ->keys('[data-family-toggle][data-slug=fantasy]', 'End')
        ->assertScript('document.activeElement.matches("[data-family-toggle]") && document.activeElement.dataset.slug !== "fantasy"', true)
        ->keys('[data-family-toggle][data-slug=fantasy]', 'Home')
        ->assertScript('document.activeElement.matches("[data-genre=fantasy]")', true);
});

test('every control shows a focus ring and is a 44px target', function () {
    $page = visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1);

    $page->click('[data-family-toggle][data-slug=fantasy]')
        ->assertScript('Array.from(document.querySelectorAll("aside button, aside label.filter-row, aside [data-family-toggle]")).filter((el) => el.offsetParent !== null).every((el) => el.getBoundingClientRect().height >= 44 - 0.5)', true)
        ->script('document.querySelector("[data-genre=fantasy]").focus()');
    $page->keys('[data-genre=fantasy]', 'Tab')
        ->assertScript('getComputedStyle(document.activeElement).boxShadow !== "none"', true);
});

test('the panel passes the audit open on a family, with a search, on a phone, and in the sheet', function () {
    visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1)
        ->click('[data-family-toggle][data-slug=fantasy]')
        ->click('[data-genre=isekai]')->wait(1.5)
        ->click('[data-genre=fantasy]')->wait(1.2)
        ->click('button[aria-controls=fp-warnings]')->click('button[aria-controls=fp-themes]')->click('button[aria-controls=fp-tropes]')->wait(0.4)
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();

    visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1)
        ->type('#fp-search-genres', 'romance')->wait(0.6)
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();

    visit(route('search', ['q' => 'dune', 'filters' => ['genres' => ['isekai'], 'excludeWarnings' => ['gore']]]), $this->phone)->wait(1.5)
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues()
        ->click('[data-flux-modal-trigger] button')->wait(0.8)
        ->click('dialog [data-family-toggle][data-slug=fantasy]')->click('dialog button[aria-controls=fp-demographics]')->wait(0.4)
        ->assertNoAccessibilityIssues();
});

test('there is no horizontal overflow at 390px, on the page or in the sheet', function () {
    $overflow = 'document.documentElement.scrollWidth <= document.documentElement.clientWidth';

    visit(route('search', ['q' => 'dune', 'filters' => ['genres' => ['isekai'], 'themes' => ['war'], 'excludeWarnings' => ['gore', 'violence'], 'tropes' => ['chosen-one']]]), $this->phone)->wait(1.5)
        ->assertScript($overflow, true)
        ->click('[data-flux-modal-trigger] button')->wait(0.8)
        ->click('dialog [data-family-toggle][data-slug=fantasy]')
        ->click('dialog button[aria-controls=fp-warnings]')->click('dialog button[aria-controls=fp-more]')->wait(0.4)
        ->assertScript($overflow, true)
        ->assertScript('document.querySelector("dialog").scrollWidth <= document.querySelector("dialog").clientWidth', true);

    visit(route('discover'), $this->phone)->wait(1.5)->assertScript($overflow, true);
});

test('discover narrows with the same panel, and the format tiles and top genres join the same filters', function () {
    $page = visit(route('discover'), $this->desktop)->wait(1.5);
    $side = 'aside[aria-labelledby=discover-filters-title]';

    $page->assertScript('document.querySelectorAll("'.$side.' [data-family-toggle]").length', 16)
        ->click($side.' [data-family-toggle][data-slug=fantasy]')
        ->click($side.' [data-genre=isekai]')->wait(1.5)
        ->assertScript($this->chips, 'Isekai')
        ->assertScript($this->count, '3 stories')
        ->assertScript($this->query.'.includes("filters[genres][0]=isekai")', true)
        ->click('[data-format-tile]')->wait(1.5)
        ->assertScript($this->chips, 'Manga|Isekai')
        ->assertScript(box('isekai').'.checked', true)
        ->assertScript('document.querySelector("'.$side.' [data-filter=mediaTypes][value=manga]").checked', true)
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();

    visit(route('discover'), $this->phone)->wait(1.5)
        ->click('[data-flux-modal-trigger] button')->wait(0.8)
        ->click('dialog [data-family-toggle][data-slug=fantasy]')
        ->click('dialog [data-genre=isekai]')
        ->click('dialog button:has-text("Apply filters")')->wait(1.5)
        ->assertScript($this->chips, 'Isekai')
        ->assertScript($this->count, '3 stories')
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
});

test('the result count is announced: a polite status region that changes with the filters', function () {
    $page = visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1);

    $page->assertScript('document.querySelector("[data-test=result-count]").getAttribute("role")', 'status')
        ->click('[data-family-toggle][data-slug=fantasy]')
        ->click('[data-genre=litrpg]')->wait(1.5)
        ->assertScript($this->count, '1 story');
});

test('leaving the page and coming back with the back button builds the panel again, without errors', function () {
    $page = visit(route('search', ['q' => 'dune']), $this->desktop)->wait(1);

    $page->script('Livewire.navigate('.json_encode(route('discover')).')');
    $page->wait(2)->assertSee('Find Stories')
        ->script('history.back()');
    $page->wait(2)
        ->assertScript('document.querySelectorAll("aside [data-family-toggle]").length', 16)
        ->click('[data-family-toggle][data-slug=fantasy]')
        ->click('[data-genre=litrpg]')->wait(1.5)
        ->assertScript($this->chips, 'LitRPG')
        ->assertNoJavaScriptErrors();
});
