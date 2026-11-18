<?php

use App\Models\ContentSource;
use App\Models\User;
use App\Reader\Cbz\CbzPageUrl;
use Database\Factories\ContentSourceFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

/*
 * The signed, user-bound page route for comics: one image entry streamed from the zip, typed from its bytes.
 */

beforeEach(function () {
    Storage::fake(ContentSource::disk());
    Cache::flush();

    $this->reader = User::factory()->create();
    $this->source = ContentSource::factory()->publicDomain()->verified()->cbz(3)->create();
    $this->page = fn (int $n = 1, ?ContentSource $source = null) => CbzPageUrl::for($this->reader, $source ?? $this->source, $n);
});

test('page one streams the right bytes with an image type and safe headers', function () {
    $response = $this->actingAs($this->reader)->get(($this->page)(1));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Disposition', 'inline')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->streamedContent())->toBe(ContentSourceFactory::png(1));

    expect($this->actingAs($this->reader)->get(($this->page)(3))->streamedContent())->toBe(ContentSourceFactory::png(3));
});

test('a page out of range is 404', function (int $n) {
    $this->actingAs($this->reader)->get(($this->page)($n))->assertNotFound();
})->with([0, 4, 999]);

test('a source that is not a cbz is 404', function () {
    $source = ContentSource::factory()->publicDomain()->verified()->ready()->create();

    $this->actingAs($this->reader)->get(($this->page)(1, $source))->assertNotFound();
});

test('a jpg entry whose bytes are html is 404', function () {
    $temp = tempnam(sys_get_temp_dir(), 'pg');
    $zip = new ZipArchive;
    $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('1.jpg', '<html><script>alert(1)</script></html>');
    $zip->close();
    $source = ContentSource::factory()->publicDomain()->verified()->cbz(1)->create();
    Storage::disk(ContentSource::disk())->put($source->storage_path, file_get_contents($temp));
    unlink($temp);

    $this->actingAs($this->reader)->get(($this->page)(1, $source))->assertNotFound();
});

test('a page above the size cap is 404 and one within it is streamed whole', function () {
    $png = ContentSourceFactory::png(1).random_bytes(1500000);
    $temp = tempnam(sys_get_temp_dir(), 'pg');
    $zip = new ZipArchive;
    $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('1.png', $png);
    $zip->setCompressionName('1.png', ZipArchive::CM_STORE);
    $zip->close();
    $source = ContentSource::factory()->publicDomain()->verified()->cbz(1)->create();
    Storage::disk(ContentSource::disk())->put($source->storage_path, file_get_contents($temp));
    unlink($temp);

    config(['bookflix.reader.archive.max_page_mb' => 1]);
    $refused = $this->actingAs($this->reader)->get(($this->page)(1, $source))->assertNotFound();
    expect($refused->getContent())->toBe('This page is not available.');

    config(['bookflix.reader.archive.max_page_mb' => 2]);
    $response = $this->actingAs($this->reader)->get(($this->page)(1, $source))->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($response->streamedContent())->toBe($png)->and($response->headers->get('Content-Length'))->toBe((string) strlen($png));
});

test('a damaged archive is 404 with a short plain body', function () {
    Storage::disk(ContentSource::disk())->put($this->source->storage_path, 'not a zip');

    $response = $this->actingAs($this->reader)->get(($this->page)(1))->assertNotFound();

    expect($response->headers->get('Content-Type'))->toContain('text/plain')
        ->and(strlen($response->getContent()))->toBeLessThan(100)
        ->and($response->getContent())->not->toContain('Exception');
});

test('an expired url is refused', function () {
    $url = URL::temporarySignedRoute('reader.page', now()->subMinute(), ['source' => $this->source->id, 'page' => 1, 'user' => $this->reader->id], absolute: false);

    $this->actingAs($this->reader)->get($url)->assertForbidden();
});

test('an unsigned address and a tampered page are refused', function () {
    $this->actingAs($this->reader)->get(route('reader.page', ['source' => $this->source->id, 'page' => 1, 'user' => $this->reader->id]))->assertForbidden();
    $this->actingAs($this->reader)->get(str_replace('/page/1?', '/page/2?', ($this->page)(1)))->assertForbidden();
});

test('another reader with a valid url is refused', function () {
    $this->actingAs(User::factory()->create())->get(($this->page)(1))->assertForbidden();
});

test('an unverified source is refused', function () {
    $source = ContentSource::factory()->publicDomain()->cbz(2)->create();

    $this->actingAs($this->reader)->get(($this->page)(1, $source))->assertForbidden();
});

test('a guest is sent to sign in', function () {
    $this->get(($this->page)(1))->assertRedirect(route('login'));
});

test('an unverified email address is refused', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(CbzPageUrl::for($user, $this->source, 1))->assertRedirect();
});

test('an admin cannot use the reader route', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(CbzPageUrl::for($admin, $this->source, 1))->assertRedirect(route('admin.index'));
});

test('the url is signed relative and lasts the configured minutes', function () {
    config(['bookflix.reader.url_ttl_minutes' => 10]);
    $url = ($this->page)(2);

    expect($url)->toStartWith('/reader/'.$this->source->id.'/page/2?')->and($url)->not->toContain('http');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    expect((int) $query['expires'])->toBe(now()->addMinutes(10)->getTimestamp())->and($query['user'])->toBe((string) $this->reader->id);
});

test('a page on a disk that is not local copies the archive once, and removes the copy when the stream ends', function () {
    // Not the plain local adapter, so every use of the file is a copy to a temporary one; it counts how often a file is read out of it.
    $root = sys_get_temp_dir().'/page-route-'.uniqid();
    $adapter = new LocalFilesystemAdapter($root);
    $counting = new class(new Filesystem($adapter), $adapter, ['root' => $root]) extends FilesystemAdapter
    {
        public int $reads = 0;

        public function readStream($path)
        {
            $this->reads++;

            return parent::readStream($path);
        }
    };
    Storage::set('counting', $counting);
    config(['bookflix.reader.disk' => 'counting']);

    $source = ContentSource::factory()->publicDomain()->verified()->cbz(2)->create();
    Cache::flush();
    $before = count(glob(sys_get_temp_dir().'/bookflix-local-*') ?: []);
    $counting->reads = 0;

    $response = $this->actingAs($this->reader)->get(($this->page)(2, $source))->assertOk();
    $body = $response->streamedContent();

    // One read to index the archive (not cached yet), one for the sniff and the stream together.
    expect($body)->toBe(ContentSourceFactory::png(2))
        ->and($counting->reads)->toBe(2)
        ->and(count(glob(sys_get_temp_dir().'/bookflix-local-*') ?: []))->toBe($before);

    $counting->reads = 0;
    $this->actingAs($this->reader)->get(($this->page)(1, $source))->assertOk()->streamedContent();

    // The index is cached now: the page is the only copy.
    expect($counting->reads)->toBe(1);

    (new Illuminate\Filesystem\Filesystem)->deleteDirectory($root);
});
