<?php

use App\Models\DuplicateCandidate;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Illuminate\Support\Facades\Http;

/*
 * The admin shell in a real browser: the sidebar drawer on a phone (keyboard, focus), and the accessibility audit of the pages an admin works in.
 */

beforeEach(function () {
    Http::fake(['*/health' => Http::response(['status' => 'ok'])]);
    $this->actingAs(User::factory()->admin()->create());
    $this->viewports = [
        'desktop' => ['viewport' => ['width' => 1440, 'height' => 900]],
        'phone' => ['viewport' => ['width' => 390, 'height' => 844]],
    ];
});

test('the sidebar drawer opens from the menu button and closes by keyboard, trapping focus and giving it back', function () {
    $inDrawer = 'document.querySelector("[data-flux-sidebar]").contains(document.activeElement)';
    $page = visit('/admin', ['viewport' => ['width' => 390, 'height' => 844], 'reducedMotion' => 'reduce'])->wait(1);

    $page->assertNoJavaScriptErrors()
        ->assertScript('document.querySelector("[data-flux-sidebar]").getBoundingClientRect().right <= 0', true)
        ->script('document.querySelector(\'button[aria-label="Open menu"]\').focus()');

    $page->keys(':focus', 'Enter')->wait(0.6)
        ->assertScript('document.querySelector("[data-flux-sidebar]").getBoundingClientRect().left >= 0', true);

    // Focus stays inside the open drawer however far the person tabs.
    foreach (range(1, 14) as $step) {
        $page->keys(':root', 'Tab');
    }

    $page->assertScript($inDrawer, true)
        ->keys(':root', 'Escape')->wait(0.6)
        ->assertScript('document.querySelector("[data-flux-sidebar]").getBoundingClientRect().right <= 0', true)
        ->assertScript('document.activeElement.getAttribute("aria-label") === "Open menu"', true);
});

test('the admin pages pass the audit with no console errors, on desktop and on a phone', function (string $viewport) {
    $this->seed(DeweySeeder::class);
    DuplicateCandidate::factory()->count(2)->create();
    classified(Media::factory()->count(2)->create(), '813', status: 'pending');

    foreach (['/admin', '/admin/providers', '/admin/duplicates', '/admin/classifications', '/admin/models'] as $path) {
        visit($path, $this->viewports[$viewport])->wait(1)
            ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
    }
})->with(['desktop', 'phone']);
