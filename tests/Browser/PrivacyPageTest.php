<?php

use App\Jobs\ExportUserData;
use App\Models\Media;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Settings, Privacy in a real browser: the export card with its button and the status it keeps, the audit, the page width and the keyboard.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create(['email' => 'reader@example.test']);
    $this->actingAs($this->user);

    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 390, 'height' => 844]];

    $this->status = 'document.querySelector("[role=status]").textContent.replace(/\s+/g, " ").trim()';
    $this->button = 'document.querySelector("[data-test=export-data-button]")';
});

test('the privacy page with the export card passes the shared checks on desktop and on a phone, at rest and after asking', function () {
    foreach ([$this->desktop, $this->phone] as $viewport) {
        visit(route('privacy.edit'), $viewport)->wait(1)
            ->assertSee('Export Your Data')
            ->assertScript($this->status, '')
            ->assertNoJavaScriptErrors()
            ->assertNoAccessibilityIssues();
    }

    Queue::fake();

    foreach ([$this->desktop, $this->phone] as $viewport) {
        $this->actingAs(User::factory()->create());

        $page = visit(route('privacy.edit'), $viewport)->wait(1);
        $page->click('[data-test=export-data-button]')->wait(1)
            ->assertSee('Preparing your export')
            ->assertScript($this->button.'.disabled', true)
            ->assertNoJavaScriptErrors()
            ->assertNoAccessibilityIssues();
    }
});

test('asking for the export says it is being prepared, and the button waits', function () {
    Queue::fake();

    $page = visit(route('privacy.edit'), $this->desktop)->wait(1);

    $page->click('[data-test=export-data-button]')->wait(1)
        ->assertScript($this->status, 'Preparing your export. We will email reader@example.test when it is ready, usually within a few minutes. You can ask for another in 10 minutes.')
        ->assertScript($this->button.'.disabled', true)
        ->assertScript('document.querySelectorAll("[data-test=export-download-link]").length', 0);

    Queue::assertPushed(ExportUserData::class, 1);
});

test('an export that has been made shows its download link, and the link sends a zip', function () {
    Rating::factory()->create(['user_id' => $this->user->id, 'media_id' => Media::factory()->create(['title' => 'Kept Tale'])->id, 'score' => 8]);

    $page = visit(route('privacy.edit'), $this->desktop)->wait(1);

    // The queue runs jobs at once here, so the export is written while the button's request is answered.
    $page->click('[data-test=export-data-button]')->wait(1.5)
        ->assertSee('Your export is ready')
        ->assertScript('document.querySelectorAll("[data-test=export-download-link]").length', 1)
        ->assertScript('fetch(document.querySelector("[data-test=export-download-link]").href).then((response) => response.status + " " + response.headers.get("content-type"))', '200 application/zip')
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues();
});

test('the export button is a 44px target that the keyboard reaches and works', function () {
    $page = visit(route('privacy.edit'), $this->desktop)->wait(1);

    $page->assertScript($this->button.'.getBoundingClientRect().height >= 44', true)
        ->script($this->button.'.focus()');

    $page->assertScript('document.activeElement === '.$this->button, true)
        ->assertScript('getComputedStyle(document.activeElement).outlineStyle !== "none" || getComputedStyle(document.activeElement).boxShadow !== "none"', true)
        ->keys('[data-test=export-data-button]', 'Enter')->wait(1.5)
        ->assertSee('Your export is ready');
});

test('the privacy page does not overflow at 390px with the export card in any state', function () {
    $page = visit(route('privacy.edit'), $this->phone)->wait(1);
    $page->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->click('[data-test=export-data-button]')->wait(1.5)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true);
});
