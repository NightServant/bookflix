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
            ->assertSee('Start with volume 1')
            ->assertSee('[01.5]')
            ->assertNoJavaScriptErrors()
            ->assertNoAccessibilityIssues();
    }
});

test('the call to action opens volume 1 and the series link on the story page comes back', function () {
    $first = $this->series->orderedMedia()->first();

    $page = visit(route('series.show', $this->series->slug))->wait(1)
        ->click('Start with volume 1')->wait(1)
        ->assertPathIs('/stories/'.$first->slug)
        ->assertSee('Part of');

    $page->click('Frankenstein Cycle')->wait(1)->assertPathIs('/series/'.$this->series->slug)->assertSee('Volumes')->assertNoJavaScriptErrors();
});
