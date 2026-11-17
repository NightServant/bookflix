<?php

use App\Models\ContentSource;
use App\Models\Media;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

/*
 * The upload screen in a real browser: it passes the shared checks at both widths, a file picked through the native input shows its row, and a
 * tiny PDF goes all the way (Livewire's temporary upload, then UploadContentSource) into Your files, where Read opens the reader on it.
 */

/**
 * The browser-test server hands a multipart field named `files[]` to Laravel under that literal name, so Livewire's upload endpoint would see no
 * `files`. This puts them where PHP's own parser would, for the upload route only. A test harness shim; the app code is untouched.
 */
class FoldUploadedFilesShim
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->files->has('files[]')) {
            $request->files->set('files', Arr::wrap($request->files->get('files[]')));
            $request->files->remove('files[]');
        }

        return $next($request);
    }
}

beforeEach(function () {
    app('router')->prependMiddlewareToGroup('web', FoldUploadedFilesShim::class);
    Storage::fake(ContentSource::disk());
    Storage::fake('tmp-for-tests');

    $this->reader = User::factory()->create();
    $this->media = Media::factory()->novel()->create(['title' => 'The Open Book']);
    $this->actingAs($this->reader);
    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812]];

    // A PDF, not an EPUB: the browser-test server reads an upload body as text, so every byte above 0x7F turns into "?", which breaks a zip. A hand-written PDF is plain ASCII.
    $this->filePath = sys_get_temp_dir().'/alice-in-wonderland.pdf';
    file_put_contents($this->filePath, tinyPdf());

});

afterEach(fn () => @unlink($this->filePath));

test('the page passes the shared checks on a desktop and on a phone', function () {
    foreach ([$this->desktop, $this->phone] as $viewport) {
        visit(route('library.upload', ['story' => $this->media->slug]), $viewport)->wait(1)
            ->assertSee('Add your own file')->assertSee('Your files')->assertSee('Choose a file')
            ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
            ->assertNoJavaScriptErrors()->assertNoConsoleLogs()->assertNoAccessibilityIssues();
    }
});

test('a file picked through the native input shows its row, and can be removed', function () {
    visit(route('library.upload'), $this->desktop)->wait(1)
        ->attach('#upload-file', $this->filePath)->wait(1.5)
        ->assertSeeIn('[data-chosen-file]', 'alice-in-wonderland.pdf')
        ->assertSeeIn('[data-chosen-file]', 'PDF ·')
        ->assertNoJavaScriptErrors()->assertNoConsoleLogs()->assertNoAccessibilityIssues()
        ->click('[data-chosen-file] button')->wait(0.8)
        ->assertMissing('[data-chosen-file]');
});

test('a tiny PDF uploads end to end, shows in Your files, and Read opens it', function () {
    $page = visit(route('library.upload', ['story' => $this->media->slug]), $this->desktop)->wait(1)
        ->attach('#upload-file', $this->filePath)->wait(1.5)
        ->click('text=This is my own copy, and it has no DRM.')
        ->click('form[wire\:submit] button[type=submit]')->wait(2.5)
        ->assertSee('Added. Open it from the story or from Your files.')
        ->assertSeeIn('#your-files + div, section[aria-labelledby=your-files]', 'The Open Book')
        ->assertNoJavaScriptErrors()->assertNoConsoleLogs();

    $source = ContentSource::sole();
    expect($source->owner_user_id)->toBe($this->reader->id);

    $page->click('section[aria-labelledby=your-files] a[aria-label="Read The Open Book"]')->wait(1.5)
        ->assertScript('location.pathname', '/read/'.$this->media->slug)
        ->assertScript('new URLSearchParams(location.search).get("source")', (string) $source->id)
        ->assertNoJavaScriptErrors();
});
