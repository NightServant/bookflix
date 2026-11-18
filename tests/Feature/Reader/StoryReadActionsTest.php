<?php

use App\Models\ContentSource;
use App\Models\Media;
use App\Models\ReadingProgress;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * The story page's reading actions: the red "Start reading" / "Read your copy" / "Continue reading · n%" and the "Where to read" card of verified links.
 */

afterEach(fn () => Livewire::flushState());

beforeEach(function () {
    Storage::fake(ContentSource::disk());

    $this->reader = User::factory()->create();
    $this->media = Media::factory()->novel()->create(['title' => 'Vagabond Tides']);
    $this->page = fn () => Livewire::actingAs($this->reader)->test('pages::stories.show', ['slug' => $this->media->slug]);
    $this->link = function (array $attributes = [], bool $verified = true) {
        $factory = ContentSource::factory()->for($this->media)->licensed()->externalLink();

        return ($verified ? $factory->verified() : $factory)->create($attributes);
    };
});

describe('the red action', function () {
    test('a verified public-domain file offers Start reading, linking to the reader', function () {
        ContentSource::factory()->for($this->media)->publicDomain()->verified()->ready()->create();

        ($this->page)()->assertSee('Start reading')->assertSee(route('reader.show', $this->media->slug), false)->assertDontSee('Read your copy')->assertDontSee('Where to read');
    });

    test('the reader\'s own upload offers Read your copy, ahead of a public file, with a note that only they can read it', function () {
        ContentSource::factory()->for($this->media)->publicDomain()->verified()->ready()->create();
        ContentSource::factory()->for($this->media)->userOwned($this->reader)->ready()->create();

        ($this->page)()->assertSee('Read your copy')->assertDontSee('Start reading')->assertSee('Only you can read it');
    });

    test('someone else\'s upload offers nothing', function () {
        ContentSource::factory()->for($this->media)->userOwned(User::factory()->create())->ready()->create();

        ($this->page)()->assertDontSee('Read your copy')->assertDontSee('Start reading');
    });

    test('part way through that file it reads Continue reading with the percent', function () {
        $source = ContentSource::factory()->for($this->media)->publicDomain()->verified()->ready()->create();
        ReadingProgress::create(['user_id' => $this->reader->id, 'media_id' => $this->media->id, 'content_source_id' => $source->id, 'locator' => ['fraction' => 0.42], 'percent' => 42.7, 'last_read_at' => now()]);

        ($this->page)()->assertSee('Continue reading · 42%')->assertDontSee('Start reading');
    });

    test('a place logged by hand (no file) or in another file does not turn it into Continue', function () {
        $source = ContentSource::factory()->for($this->media)->publicDomain()->verified()->ready()->create();
        ReadingProgress::create(['user_id' => $this->reader->id, 'media_id' => $this->media->id, 'content_source_id' => null, 'percent' => 42, 'last_read_at' => now()]);

        ($this->page)()->assertSee('Start reading')->assertDontSee('Continue reading');
        expect($source->exists)->toBeTrue();
    });

    test('a finished story reads Start reading again', function () {
        $source = ContentSource::factory()->for($this->media)->publicDomain()->verified()->ready()->create();
        ReadingProgress::create(['user_id' => $this->reader->id, 'media_id' => $this->media->id, 'content_source_id' => $source->id, 'percent' => 100, 'last_read_at' => now()]);

        ($this->page)()->assertSee('Start reading')->assertDontSee('Continue reading');
    });

    test('an unverified file offers nothing', function () {
        ContentSource::factory()->for($this->media)->publicDomain()->ready()->create();

        ($this->page)()->assertDontSee('Start reading');
    });

    test('the page keeps its library controls beside the action', function () {
        ContentSource::factory()->for($this->media)->publicDomain()->verified()->ready()->create();

        ($this->page)()->assertSeeLivewire('library-controls');
    });
});

describe('where to read', function () {
    test('only verified links out are listed, each opening in a new tab', function () {
        ($this->link)(['url' => 'https://publisher.example/read', 'rights_holder' => 'Official publisher site', 'license' => 'Read and buy volumes']);
        ($this->link)(['url' => 'https://www.reading-app.example/title/1', 'rights_holder' => null, 'license' => 'New chapters weekly']);
        ($this->link)(['url' => 'https://unverified.example/x', 'rights_holder' => 'Unverified shop'], verified: false);
        ContentSource::factory()->for($this->media)->licensed()->verified()->externalLink()->create(['url' => 'javascript:alert(1)', 'rights_holder' => 'Script']);
        ContentSource::factory()->for(Media::factory()->novel()->create())->licensed()->verified()->externalLink()->create(['url' => 'https://other-story.example', 'rights_holder' => 'Other story']);

        $html = ($this->page)()
            ->assertSee('Where to read')
            ->assertSee('Bookflix doesn\'t host this story. These links go to its publishers.')
            ->assertSee('Official publisher site')
            ->assertSee('Read and buy volumes')
            ->assertSee('reading-app.example')
            ->assertSee('New chapters weekly')
            ->assertDontSee('Unverified shop')
            ->assertDontSee('javascript:')
            ->assertDontSee('Other story')
            ->html();

        expect(substr_count($html, 'target="_blank"'))->toBe(2)
            ->and(substr_count($html, 'rel="noopener noreferrer"'))->toBe(2)
            ->and(substr_count($html, '(opens in a new tab)'))->toBe(2)
            ->and($html)->toContain('href="https://publisher.example/read"');
    });

    test('the card links to the upload form with this story chosen, once that form exists', function () {
        ($this->link)();

        $html = ($this->page)()->html();

        Route::has('library.upload')
            ? expect($html)->toContain('Have a DRM-free copy? Add your own file')->toContain(route('library.upload', ['story' => $this->media->slug]))
            : expect($html)->not->toContain('Have a DRM-free copy');
    });

    test('it is hidden while the reader has a file to open', function () {
        ($this->link)();
        ContentSource::factory()->for($this->media)->publicDomain()->verified()->ready()->create();

        ($this->page)()->assertDontSee('Where to read')->assertSee('Start reading');
    });

    test('it is hidden for a story with no verified links', function () {
        ($this->link)(verified: false);

        ($this->page)()->assertDontSee('Where to read');
    });

    test('another reader\'s upload does not hide it', function () {
        ($this->link)();
        ContentSource::factory()->for($this->media)->userOwned(User::factory()->create())->ready()->create();

        ($this->page)()->assertSee('Where to read');
    });
});
