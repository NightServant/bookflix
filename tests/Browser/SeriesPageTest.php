<?php

use App\Models\Media;
use App\Models\Series;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->series = Series::factory()->create(['title' => 'Frankenstein Cycle']);

    foreach ([[1, 'Frankenstein', '/favicon.svg'], [1.5, 'Frankenstein Unbound', null], [2, 'Bride of Frankenstein', '/favicon.svg']] as [$position, $title, $cover]) {
        $this->series->media()->attach(Media::factory()->novel()->create(['title' => $title, 'cover_url' => $cover, 'publication_year' => 1818]), ['position' => $position]);
    }
});

test('the series page passes the shared checks on desktop and on a phone', function () {
    foreach ([['width' => 1280, 'height' => 900], ['width' => 375, 'height' => 812]] as $viewport) {
        visit(route('series.show', $this->series->slug), ['viewport' => $viewport])->wait(1)
            ->assertSee('See volume 1')
            ->assertSee('[01.5]')
            ->assertNoJavaScriptErrors()
            ->assertNoAccessibilityIssues();
    }
});

test('the call to action opens volume 1 and the series link on the story page comes back', function () {
    $first = $this->series->orderedMedia()->first();

    $page = visit(route('series.show', $this->series->slug))->wait(1)
        ->click('See volume 1')->wait(1)
        ->assertPathIs('/stories/'.$first->slug)
        ->assertSee('Part of');

    $page->click('Frankenstein Cycle')->wait(1)->assertPathIs('/series/'.$this->series->slug)->assertSee('Volumes')->assertNoJavaScriptErrors();
});

test('the header cover, the LCP image, paints at full opacity on the first frame while the text still reveals', function () {
    $page = visit(route('series.show', $this->series->slug), ['viewport' => ['width' => 1280, 'height' => 900]]);

    // Motion is allowed here: nothing from the cover up to the header may be hidden or animating, not even for a moment, and no reveal may own it.
    $page->assertScript('document.querySelector("header img[fetchpriority=high]").closest("[x-motion-reveal]") === null', true)
        ->assertScript('(() => {
        const chain = [];
        for (let el = document.querySelector("header img[fetchpriority=high]"); el && el !== document.body; el = el.parentElement) chain.push(el);

        return chain.length > 3 && chain.every((el) => el.style.opacity === "" && getComputedStyle(el).opacity === "1" && el.getAnimations().length === 0);
    })()', true)
        ->wait(1)
        ->assertScript('getComputedStyle(document.querySelector("header h1")).opacity', '1')
        ->assertNoJavaScriptErrors();
});
