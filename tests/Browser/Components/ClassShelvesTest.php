<?php

use App\Models\Media;
use App\Models\User;
use Database\Seeders\DeweySeeder;

test('the explore page renders its class shelves with the shelf and cover tile components', function () {
    $this->seed(DeweySeeder::class);
    classified(Media::factory()->count(14)->create(['cover_url' => '/favicon.svg']), '813');
    $this->actingAs(User::factory()->create());

    visit(route('explore.index'))->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->assertScript('document.querySelectorAll("section[aria-labelledby^=shelf-] ul li a[href*=stories]").length', 12)
        ->assertScript('getComputedStyle(document.querySelector("[aria-label^=\"Next in\"]").parentElement).display', 'flex')
        ->click('[aria-label^="Next in"]')
        ->wait(1)
        ->assertScript('document.querySelector("section[aria-labelledby^=shelf-] ul").scrollLeft > 100', true);
});
