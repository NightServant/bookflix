<?php

use App\Enums\ModelKind;
use App\Enums\ModelStatus;
use App\Models\Collection;
use App\Models\DuplicateCandidate;
use App\Models\Media;
use App\Models\ModelVersion;
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
    Collection::factory()->public()->editorial()->create();
    Collection::factory()->public()->create();

    foreach (['/admin', '/admin/providers', '/admin/duplicates', '/admin/classifications', '/admin/staff-picks', '/admin/models'] as $path) {
        visit($path, $this->viewports[$viewport])->wait(1)
            ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
    }
})->with(['desktop', 'phone']);

test('the model comparison draws its chart from a lazily loaded chunk and passes the audit, on desktop and on a phone', function (string $viewport) {
    foreach (['2026-09-27 03:00' => true, '2026-10-04 03:00' => false, '2026-10-11 03:00' => true] as $trained => $promoted) {
        ModelVersion::factory()->create([
            'name' => 'item-cf', 'kind' => ModelKind::ItemCf, 'trained_at' => $trained,
            'status' => $trained === '2026-10-11 03:00' ? ModelStatus::Active : ModelStatus::Retired,
            'metrics' => ['ndcg@10' => 0.15, 'coverage' => 0.4, 'genre_diversity' => 0.6, 'novelty' => 8.1, 'promoted' => $promoted],
        ]);
    }

    $page = visit('/admin/models/compare', $this->viewports[$viewport])->wait(2)
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues()
        ->assertScript('document.querySelector("[data-test=version-chart] canvas").width > 0', true);

    // Switching the metric redraws in place and marks the pressed chip.
    $page->click('Coverage')->wait(0.5)
        ->assertScript('document.querySelector("[role=group] button[aria-pressed=true]").textContent.trim()', 'Coverage')
        ->assertNoJavaScriptErrors();
})->with(['desktop', 'phone']);
