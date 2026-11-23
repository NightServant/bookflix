<?php

use App\Models\ContentSource;
use App\Models\Media;
use App\Models\ReadingProgress;
use App\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * The reader page (`reader.show`): who may open it, which file it picks, the signed addresses it hands the browser, the CSP of this route alone,
 * the Livewire calls the engines make (each asks the access gate again) and the four states.
 */

// Livewire::withoutLazyLoading() lasts for the rest of the process unless its state is flushed.
afterEach(fn () => Livewire::flushState());

beforeEach(function () {
    Storage::fake(ContentSource::disk());

    $this->reader = User::factory()->create();
    $this->media = Media::factory()->novel()->create(['title' => 'The Open Book']);
    $this->source = ContentSource::factory()->for($this->media)->publicDomain()->verified()->ready()->create();
    $this->open = fn (?Media $media = null, array $query = []) => $this->actingAs($this->reader)->get(route('reader.show', ['slug' => ($media ?? $this->media)->slug, ...$query]));
});

/** The value of a `data-` attribute of the engine container. */
function engineData(string $html, string $name): ?string
{
    return preg_match('/data-reader-engine="[^"]*"[^>]*\s'.preg_quote($name, '/').'="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : null;
}

describe('access', function () {
    test('a guest is sent to sign in', function () {
        $this->get(route('reader.show', $this->media->slug))->assertRedirect(route('login'));
    });

    test('an unverified account is sent to verify', function () {
        $this->actingAs(User::factory()->unverified()->create())->get(route('reader.show', $this->media->slug))->assertRedirect(route('verification.notice'));
    });

    test('an admin is refused the reader side', function () {
        $this->actingAs(User::factory()->admin()->create())->get(route('reader.show', $this->media->slug))->assertRedirect(route('admin.index'));
    });

    test('a story that does not exist is 404', function () {
        $this->actingAs($this->reader)->get(route('reader.show', 'no-such-story'))->assertNotFound();
    });

    test('a story behind the mature gate is 404 for a reader who has not opted in, and opens for one who has', function () {
        $this->media->update(['age_rating' => 'adult']);

        ($this->open)()->assertNotFound();

        $this->actingAs(User::factory()->showsMature()->create())->get(route('reader.show', $this->media->slug))->assertOk();
    });

    test('a deleted story is 404', function () {
        $this->media->delete();

        ($this->open)()->assertNotFound();
    });

    test('a story with nothing this reader may open goes back to the story page', function () {
        $this->source->update(['verified_at' => null, 'verified_by' => null]);

        ($this->open)()->assertRedirect(route('stories.show', $this->media->slug))
            ->assertSessionHas('toast', 'There is nothing to read here for this story yet.');
    });

    test('a file withdrawn after the reader started tells them on the story page that their place is kept', function () {
        ReadingProgress::factory()->create(['user_id' => $this->reader->id, 'media_id' => $this->media->id, 'content_source_id' => $this->source->id, 'percent' => 13]);
        $this->source->update(['verified_at' => null, 'verified_by' => null]);

        ($this->open)()->assertRedirect(route('stories.show', $this->media->slug))
            ->assertSessionHas('toast', 'This book is no longer available to read here. Your place is kept.');

        $this->followingRedirects();
        ($this->open)()->assertOk()->assertSee('$flux.toast', false)->assertSee('This book is no longer available to read here.', false);
        expect(ReadingProgress::query()->where('user_id', $this->reader->id)->value('percent'))->toEqual(13);
    });

    test('a story that only has links out goes back to the story page', function () {
        $media = Media::factory()->novel()->create();
        ContentSource::factory()->for($media)->licensed()->verified()->externalLink()->create();

        ($this->open)($media)->assertRedirect(route('stories.show', $media->slug));
    });
});

describe('the source', function () {
    test('a reader gets the page with its title, a main landmark and no app chrome', function () {
        $response = ($this->open)()->assertOk()->assertSee('<main', false)->assertSee('data-reader-engine="epub"', false);

        expect($response->getContent())->toMatch('/<title>\s*The Open Book - /')->not->toContain('bottom-nav')->not->toContain('Skip to content');
    });

    test('the reader\'s own upload is preferred over a public one', function () {
        $own = ContentSource::factory()->for($this->media)->userOwned($this->reader)->ready()->create();

        expect(engineData(($this->open)()->getContent(), 'data-source-id'))->toBe((string) $own->id);
    });

    test('another reader\'s upload is never chosen', function () {
        ContentSource::factory()->for($this->media)->userOwned(User::factory()->create())->ready()->create();

        expect(engineData(($this->open)()->getContent(), 'data-source-id'))->toBe((string) $this->source->id);
    });

    test('an EPUB comes before a PDF before a CBZ', function () {
        $media = Media::factory()->novel()->create();
        $cbz = ContentSource::factory()->for($media)->publicDomain()->verified()->cbz(2)->create();
        $pdf = ContentSource::factory()->for($media)->publicDomain()->verified()->ready()->create(['format' => 'pdf']);

        expect(engineData(($this->open)($media)->getContent(), 'data-source-id'))->toBe((string) $pdf->id);

        $epub = ContentSource::factory()->for($media)->publicDomain()->verified()->ready()->create();

        expect(engineData(($this->open)($media)->getContent(), 'data-source-id'))->toBe((string) $epub->id);
        expect($cbz->id)->not->toBe($epub->id);
    });

    test('the source asked for wins when the gate allows it, and is ignored when it does not', function () {
        $pdf = ContentSource::factory()->for($this->media)->publicDomain()->verified()->ready()->create(['format' => 'pdf']);
        $unverified = ContentSource::factory()->for($this->media)->publicDomain()->ready()->create(['format' => 'pdf']);

        expect(engineData(($this->open)(query: ['source' => $pdf->id])->getContent(), 'data-source-id'))->toBe((string) $pdf->id);
        expect(engineData(($this->open)(query: ['source' => $unverified->id])->getContent(), 'data-source-id'))->toBe((string) $this->source->id);
        expect(engineData(($this->open)(query: ['source' => 'nonsense'])->getContent(), 'data-source-id'))->toBe((string) $this->source->id);
    });

    test('a source of another story is not opened through this one', function () {
        $other = ContentSource::factory()->publicDomain()->verified()->ready()->create(['format' => 'pdf']);

        expect(engineData(($this->open)(query: ['source' => $other->id])->getContent(), 'data-source-id'))->toBe((string) $this->source->id);
    });
});

describe('the engine container', function () {
    test('the stream url is signed, relative, short-lived and bound to the reader', function () {
        $url = engineData(($this->open)()->getContent(), 'data-stream-url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect($url)->toStartWith('/reader/stream/'.$this->source->id.'?')
            ->and($query['user'])->toBe((string) $this->reader->id)
            ->and($query)->toHaveKeys(['expires', 'signature']);

        $this->actingAs($this->reader)->get($url)->assertOk();
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    });

    test('it carries the page, the wire id and where to save, and is left alone by Livewire', function () {
        $html = ($this->open)()->getContent();

        expect($html)->toContain('wire:ignore data-reader-engine="epub"')
            ->and(engineData($html, 'data-progress-url'))->toBe('/reader/'.$this->source->id.'/progress')
            ->and(engineData($html, 'data-wire-id'))->not->toBeEmpty()
            ->and(engineData($html, 'data-format'))->toBe('epub');
    });

    test('where the reader left off comes with the page, the locator only for this very file', function () {
        ReadingProgress::create(['user_id' => $this->reader->id, 'media_id' => $this->media->id, 'content_source_id' => $this->source->id, 'locator' => ['cfi' => 'epubcfi(/6/8)'], 'percent' => 42, 'last_read_at' => now()]);
        $html = ($this->open)()->getContent();

        expect(json_decode(engineData($html, 'data-resume-locator'), true))->toBe(['cfi' => 'epubcfi(/6/8)'])
            ->and(engineData($html, 'data-resume-percent'))->toBe('42');

        ReadingProgress::where('user_id', $this->reader->id)->update(['content_source_id' => null]);

        expect(engineData(($this->open)()->getContent(), 'data-resume-locator'))->toBeNull()
            ->and(engineData(($this->open)()->getContent(), 'data-resume-percent'))->toBe('42');
    });

    test('the bottom bar rounds the percent down, the same as the story page', function () {
        ReadingProgress::create(['user_id' => $this->reader->id, 'media_id' => $this->media->id, 'content_source_id' => $this->source->id, 'percent' => 12.6, 'last_read_at' => now()]);

        expect(($this->open)()->getContent())->toMatch('/data-reader="percent"[^>]*>12%<\/p>/');
        $this->get(route('stories.show', $this->media->slug))->assertSee('Continue reading · 12%');
    });

    test('a locator with quotes and angle brackets can\'t break out of its attribute', function () {
        $cfi = 'epubcfi(/6/8)" onfocus="alert(1)" x="<script>';
        ReadingProgress::create(['user_id' => $this->reader->id, 'media_id' => $this->media->id, 'content_source_id' => $this->source->id, 'locator' => ['cfi' => $cfi], 'percent' => 5, 'last_read_at' => now()]);
        $html = ($this->open)()->getContent();

        expect($html)->not->toContain('onfocus="alert(1)"')->and(json_decode(engineData($html, 'data-resume-locator'), true))->toBe(['cfi' => $cfi]);
    });

    test('a PDF gets the pdf engine and a stream url', function () {
        $media = Media::factory()->novel()->create();
        ContentSource::factory()->for($media)->publicDomain()->verified()->ready()->create(['format' => 'pdf']);
        $html = ($this->open)($media)->getContent();

        expect($html)->toContain('data-reader-engine="pdf"')->and(engineData($html, 'data-stream-url'))->not->toBeNull();
    });

    test('a manga from Japan opens right to left, one from elsewhere left to right, a webcomic as a strip', function () {
        $manga = Media::factory()->manga()->create(['country_of_origin' => 'JP']);
        ContentSource::factory()->for($manga)->publicDomain()->verified()->cbz(4)->create();
        $western = Media::factory()->graphicNovel()->create(['country_of_origin' => 'US']);
        ContentSource::factory()->for($western)->publicDomain()->verified()->cbz(2)->create();
        $webtoon = Media::factory()->ofType('webcomic')->create(['country_of_origin' => 'KR']);
        ContentSource::factory()->for($webtoon)->publicDomain()->verified()->cbz(3)->create();

        $rtl = ($this->open)($manga)->getContent();
        $ltr = ($this->open)($western)->getContent();
        $strip = ($this->open)($webtoon)->getContent();

        expect($rtl)->toContain('data-reader-engine="pages"')
            ->and(engineData($rtl, 'data-direction'))->toBe('rtl')
            ->and(engineData($rtl, 'data-page-count'))->toBe('4')
            ->and(engineData($rtl, 'data-stream-url'))->toBeNull()
            ->and(engineData($ltr, 'data-direction'))->toBe('ltr')
            ->and($strip)->toContain('data-reader-engine="webtoon"')
            ->and(engineData($strip, 'data-direction'))->toBe('vertical')
            ->and(engineData($strip, 'data-page-count'))->toBe('3');
    });

    test('the end of the book card names the next story of the series, and offers Start reading when it can be opened', function () {
        $series = Series::factory()->create();
        $next = Media::factory()->novel()->create(['title' => 'Through the Looking-Glass', 'publication_year' => 1871]);
        $series->media()->attach([$this->media->id => ['position' => 1], $next->id => ['position' => 2]]);

        $without = ($this->open)()->assertSee('Next in the series')->assertSee('Through the Looking-Glass')->assertSee('See the story');
        expect($without->getContent())->not->toContain('Start reading');

        ContentSource::factory()->for($next)->publicDomain()->verified()->ready()->create();

        ($this->open)()->assertSee('Start reading')->assertSee(route('reader.show', $next->slug), false);
    });

    test('a story that is last in its series, or in none, has no next story', function () {
        ($this->open)()->assertDontSee('Next in the series');
    });
});

describe('content security policy', function () {
    test('the reader page allows blob styles and fonts', function () {
        $policy = ($this->open)()->assertOk()->headers->get('Content-Security-Policy');

        expect($policy)->toContain("style-src 'self' 'unsafe-inline' blob:")->and($policy)->toContain("font-src 'self' data: blob:");
    });

    test('the reader page takes images from itself, data and blob only, and no other route loses https', function () {
        $reader = ($this->open)()->assertOk()->headers->get('Content-Security-Policy');
        $other = $this->actingAs($this->reader)->get(route('search'))->assertOk()->headers->get('Content-Security-Policy');

        expect($reader)->toContain("img-src 'self' data: blob:;")->not->toContain('https:')
            ->and($other)->toContain("img-src 'self' data: blob: https:;");
    });

    test('no other route does', function () {
        $policy = $this->actingAs($this->reader)->get(route('search'))->assertOk()->headers->get('Content-Security-Policy');

        expect($policy)->not->toContain("style-src 'self' 'unsafe-inline' blob:")->and($policy)->toContain("font-src 'self' data:;");
    });

    test('script and frame rules of the reader page are the page-wide ones', function () {
        $policy = ($this->open)()->headers->get('Content-Security-Policy');

        expect($policy)->not->toContain("'unsafe-inline' 'nonce")->and($policy)->toContain("frame-ancestors 'none'")->toContain("object-src 'none'");
    });
});

describe('the calls the engines make', function () {
    beforeEach(function () {
        $this->comic = Media::factory()->manga()->create();
        $this->cbz = ContentSource::factory()->for($this->comic)->publicDomain()->verified()->cbz(60)->create();
        $this->page = fn () => Livewire::actingAs($this->reader)->test('pages::reader.show', ['slug' => $this->comic->slug]);
    });

    test('streamUrl hands out a signed address bound to the reader', function () {
        $component = Livewire::actingAs($this->reader)->test('pages::reader.show', ['slug' => $this->media->slug]);
        $url = $component->instance()->streamUrl();

        expect($url)->toStartWith('/reader/stream/'.$this->source->id.'?');
        $this->actingAs($this->reader)->get($url)->assertOk();
    });

    test('pageUrls signs the pages asked for, keyed by page, and caps one call at 50', function () {
        $urls = ($this->page)()->instance()->pageUrls(1, 500);

        expect($urls)->toHaveCount(50)->and(array_key_first($urls))->toBe(1)->and(array_key_last($urls))->toBe(50);
        $this->actingAs($this->reader)->get($urls[7])->assertOk();

        $rest = ($this->page)()->instance()->pageUrls(51, 50);

        expect($rest)->toHaveCount(10)->and(array_key_last($rest))->toBe(60);
    });

    test('pageUrls never goes below page 1 or beyond the last page', function () {
        $page = ($this->page)()->instance();

        expect(array_keys($page->pageUrls(-5, 3)))->toBe([1, 2, 3])
            ->and($page->pageUrls(61, 10))->toBe([])
            ->and($page->pageUrls(1, 0))->toBe([]);
    });

    test('pageUrls and streamUrl ask the gate again: a withdrawn file is 403', function () {
        $pages = ($this->page)();
        $stream = ($this->page)();
        $this->cbz->update(['verified_at' => null, 'verified_by' => null]);

        $pages->call('pageUrls', 1, 10)->assertForbidden();
        $stream->call('streamUrl')->assertForbidden();
    });

    test('pageUrls on a file that is not a comic is 404', function () {
        Livewire::actingAs($this->reader)->test('pages::reader.show', ['slug' => $this->media->slug])->call('pageUrls', 1, 5)->assertNotFound();
    });

    test('the component\'s story and source can\'t be changed from the browser', function () {
        $component = ($this->page)();

        expect(fn () => $component->set('sourceId', $this->source->id))->toThrow(Exception::class, 'locked property');
    });
});

describe('the states', function () {
    test('all four are on the page with their copy, and only the opening one shows', function () {
        ReadingProgress::create(['user_id' => $this->reader->id, 'media_id' => $this->media->id, 'content_source_id' => $this->source->id, 'locator' => ['fraction' => 0.42], 'percent' => 42, 'last_read_at' => now()]);

        $html = ($this->open)()->getContent();

        foreach ([
            'Opening the book…',
            'This file couldn&#039;t be opened',
            'It may be damaged, or in a form Bookflix can&#039;t read. Your place in the story is saved.',
            'Try again',
            'This book is no longer available to read here',
            'Its file was withdrawn. Your place is saved at 42%, and the story stays in your library.',
            'This PDF is protected by a password',
            'Bookflix can&#039;t open password-protected files. Upload a copy without a password.',
        ] as $copy) {
            expect($html)->toContain($copy);
        }

        expect(substr_count($html, 'Back to the story'))->toBeGreaterThanOrEqual(4)
            ->and($html)->toMatch('/data-reader-state="corrupt"[^>]*style="display: none;?"/')
            ->and($html)->not->toMatch('/data-reader-state="opening"[^>]*style="display: none;?"/');
    });

    test('a comic whose archive can\'t be read opens on the could-not-open card', function () {
        $comic = Media::factory()->manga()->create();
        $source = ContentSource::factory()->for($comic)->publicDomain()->verified()->cbz(2)->create();
        Storage::disk(ContentSource::disk())->put($source->storage_path, 'not a zip at all');

        $html = ($this->open)($comic)->getContent();

        expect($html)->not->toMatch('/data-reader-state="corrupt"[^>]*style="display: none;?"/')
            ->and($html)->toMatch('/data-reader-state="opening"[^>]*style="display: none;?"/');
    });

    test('the chrome has its landmarks and controls', function () {
        ($this->open)()
            ->assertSee('aria-label="Back to the story"', false)
            ->assertSee('aria-label="Contents"', false)
            ->assertSee('aria-label="Reading settings"', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('aria-modal="true"', false)
            ->assertSee('aria-labelledby="reader-settings-title"', false)
            ->assertSee('role="radiogroup"', false)
            ->assertSee('type="range"', false)
            ->assertSee('aria-valuetext="0% read"', false);
    });
});
