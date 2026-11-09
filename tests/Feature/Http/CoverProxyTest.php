<?php

use App\Discovery\CollectionPreviews;
use App\Enums\AgeRating;
use App\Enums\CoverPolicy;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\ExternalSource;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\ExternalSourceSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    // 1x1 PNG
    $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $this->user = User::factory()->create();
});

test('a cover on an allowed host is streamed from our origin with the image type and long-lived cache headers', function () {
    Http::fake(['s4.anilist.co/*' => Http::response($this->png, 200, ['Content-Type' => 'image/png'])]);
    $media = Media::factory()->create(['cover_url' => 'https://s4.anilist.co/file/cover.png']);

    $this->actingAs($this->user)->get(route('covers.show', $media))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Cache-Control', 'immutable, max-age=86400, public')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");

    Http::assertSentCount(1);
});

test('a fetched cover is kept on disk and not fetched twice', function () {
    Http::fake(['covers.openlibrary.org/*' => Http::response($this->png, 200, ['Content-Type' => 'image/png'])]);
    $media = Media::factory()->create(['cover_url' => 'https://covers.openlibrary.org/b/id/1-L.jpg']);

    $this->actingAs($this->user)->get(route('covers.show', $media))->assertOk();
    $this->actingAs($this->user)->get(route('covers.show', $media))->assertOk();

    Http::assertSentCount(1);
    Storage::disk('local')->assertExists('covers/'.sha1($media->cover_url));
});

test('a cover on a host no provider allows is never fetched', function () {
    Http::fake();
    $media = Media::factory()->create(['cover_url' => 'https://evil.example.com/cover.png']);

    $this->actingAs($this->user)->get(route('covers.show', $media))->assertNotFound();

    Http::assertNothingSent();
});

test('only https on the default port is fetched', function (string $url) {
    Http::fake();
    $media = Media::factory()->create(['cover_url' => $url]);

    $this->actingAs($this->user)->get(route('covers.show', $media))->assertNotFound();

    Http::assertNothingSent();
})->with([
    'plain http' => 'http://s4.anilist.co/cover.png',
    'other port' => 'https://s4.anilist.co:8443/cover.png',
    'userinfo trick' => 'https://s4.anilist.co@evil.example.com/cover.png',
    'local path' => '/favicon.svg',
]);

test('a media without a cover is a 404', function () {
    Http::fake();
    $media = Media::factory()->create(['cover_url' => null]);

    $this->actingAs($this->user)->get(route('covers.show', $media))->assertNotFound();

    Http::assertNothingSent();
});

test('a failed, redirected or non-image upstream response is a 404 and is not stored', function (callable $response) {
    Http::fake(['s4.anilist.co/*' => $response()]);
    $media = Media::factory()->create(['cover_url' => 'https://s4.anilist.co/file/cover.png']);

    $this->actingAs($this->user)->get(route('covers.show', $media))->assertNotFound();

    Storage::disk('local')->assertMissing('covers/'.sha1($media->cover_url));
})->with([
    'server error' => fn () => fn () => Http::response('', 500),
    'redirect' => fn () => fn () => Http::response('', 302, ['Location' => 'https://evil.example.com/x.png']),
    'html page' => fn () => fn () => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
]);

test('an image-typed response whose bytes are not a raster image is a 404 and is not stored', function (string $body) {
    Http::fake(['s4.anilist.co/*' => Http::response($body, 200, ['Content-Type' => 'image/png'])]);
    $media = Media::factory()->create(['cover_url' => 'https://s4.anilist.co/file/cover.png']);

    $this->actingAs($this->user)->get(route('covers.show', $media))->assertNotFound();

    Storage::disk('local')->assertMissing('covers/'.sha1($media->cover_url));
})->with([
    'html' => '<html><script>alert(1)</script></html>',
    'svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
]);

test('the backdrop size is the stored cover scaled to a 320px webp, kept beside it and made once', function () {
    $image = imagecreatetruecolor(640, 960);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    Http::fake(['s4.anilist.co/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
    $media = Media::factory()->create(['cover_url' => 'https://s4.anilist.co/file/cover.png']);
    $path = 'covers/'.sha1($media->cover_url);

    $response = $this->actingAs($this->user)->get(route('covers.show', [$media, 'size' => 'backdrop']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('Cache-Control', 'immutable, max-age=86400, public');

    expect(getimagesizefromstring($response->streamedContent()))->toMatchArray([0 => 320, 1 => 480]);
    Storage::disk('local')->assertExists([$path, $path.'-backdrop']);

    $this->actingAs($this->user)->get(route('covers.show', [$media, 'size' => 'backdrop']))->assertOk();
    $this->actingAs($this->user)->get(route('covers.show', $media))->assertOk()->assertHeader('Content-Type', 'image/png');
    Http::assertSentCount(1);
});

test('only a cover we already serve has a backdrop copy: a hotlinked cover keeps its own url', function () {
    ExternalSource::factory()->create(['key' => 'mangadex', 'cover_policy' => CoverPolicy::Cache]);
    ExternalSource::factory()->create(['key' => 'anilist', 'cover_policy' => CoverPolicy::Hotlink]);
    $cached = Media::factory()->create(['cover_url' => 'https://uploads.mangadex.org/covers/a/b.jpg.512.jpg']);
    $hotlinked = Media::factory()->create(['cover_url' => 'https://s4.anilist.co/file/cover.png']);

    expect($cached->coverBackdropUrl())->toBe($cached->cover_url.'&size=backdrop')
        ->and($hotlinked->coverBackdropUrl())->toBeNull()
        ->and(Media::factory()->create(['cover_url' => null])->coverBackdropUrl())->toBeNull();
});

test('guests can load a cover too, for the landing page book', function () {
    Http::fake(['s4.anilist.co/*' => Http::response($this->png, 200, ['Content-Type' => 'image/png'])]);
    $media = Media::factory()->create(['cover_url' => 'https://s4.anilist.co/file/cover.png']);

    $this->get(route('covers.show', $media))->assertOk()->assertHeader('Content-Type', 'image/png');
});

test('a guest still cannot make it fetch a host the providers do not allow', function () {
    Http::fake();
    $media = Media::factory()->create(['cover_url' => 'https://evil.example/cover.png']);

    $this->get(route('covers.show', $media))->assertNotFound();

    Http::assertNothingSent();
});

test('the 601st cover request in a minute is throttled', function () {
    Http::fake(['s4.anilist.co/*' => Http::response($this->png, 200, ['Content-Type' => 'image/png'])]);
    $media = Media::factory()->create(['cover_url' => 'https://s4.anilist.co/file/cover.png']);

    foreach (range(1, 600) as $i) {
        $this->actingAs($this->user)->get(route('covers.show', $media))->assertOk();
    }

    $this->actingAs($this->user)->get(route('covers.show', $media))->assertStatus(429);
});

test('the proxy url changes when the cover url changes', function () {
    $media = Media::factory()->create(['cover_url' => 'https://s4.anilist.co/file/a.png']);
    $before = $media->coverProxyUrl();

    $media->cover_url = 'https://s4.anilist.co/file/b.png';

    expect($media->coverProxyUrl())->not->toBe($before)->and($before)->toContain('v=');
});

test('pruning removes only covers older than the cutoff', function () {
    $disk = Storage::disk('local');
    $disk->put('covers/old', 'x');
    $disk->put('covers/new', 'x');
    touch($disk->path('covers/old'), now()->subDays(40)->getTimestamp());

    $this->artisan('bookflix:prune-covers')->assertSuccessful();

    $disk->assertMissing('covers/old');
    $disk->assertExists('covers/new');
});

test('a cover whose source caches covers is served from our origin, and one whose source hotlinks stays on its host', function () {
    ExternalSource::factory()->create(['key' => 'mangadex', 'cover_policy' => CoverPolicy::Cache]);
    ExternalSource::factory()->create(['key' => 'anilist', 'cover_policy' => CoverPolicy::Hotlink]);
    $cached = Media::factory()->create(['cover_url' => 'https://uploads.mangadex.org/covers/a/b.jpg.512.jpg']);
    $hotlinked = Media::factory()->create(['cover_url' => 'https://s4.anilist.co/file/cover.png']);

    expect($cached->cover_url)->toStartWith('/covers/'.$cached->id.'?v=')
        ->and($cached->coverSourceUrl())->toBe('https://uploads.mangadex.org/covers/a/b.jpg.512.jpg')
        ->and($cached->fresh()->getRawOriginal('cover_url'))->toBe('https://uploads.mangadex.org/covers/a/b.jpg.512.jpg')
        ->and($hotlinked->cover_url)->toBe('https://s4.anilist.co/file/cover.png');
});

test('the served cover of a cached source streams the stored upstream image', function () {
    ExternalSource::factory()->create(['key' => 'mangadex', 'cover_policy' => CoverPolicy::Cache]);
    Http::fake(['uploads.mangadex.org/*' => Http::response($this->png, 200, ['Content-Type' => 'image/png'])]);
    $media = Media::factory()->create(['cover_url' => 'https://uploads.mangadex.org/covers/a/b.jpg.512.jpg']);

    $this->get($media->cover_url)->assertOk()->assertHeader('Content-Type', 'image/png');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://uploads.mangadex.org/covers/a/b.jpg.512.jpg');
    Storage::disk('local')->assertExists('covers/'.sha1($media->coverSourceUrl()));
});

test('the discover tiles and collection previews serve a cached source\'s covers from our origin too', function () {
    ExternalSource::factory()->create(['key' => 'mangadex', 'cover_policy' => CoverPolicy::Cache]);
    $media = Media::factory()->create(['cover_url' => 'https://uploads.mangadex.org/covers/a/b.jpg.512.jpg']);
    $collection = Collection::factory()->for($this->user)->create();
    CollectionItem::factory()->for($collection)->for($media)->create(['position' => 0]);

    $this->actingAs($this->user);
    $tiles = Livewire::test('pages::discover.index')->instance()->covers();
    $previews = app(CollectionPreviews::class)->for(Collection::query()->whereKey($collection->id)->get(), $this->user);

    expect($tiles[$media->media_type_id])->toBe([$media->cover_url])
        ->and($previews[$collection->id]['covers'])->toBe([$media->cover_url])
        ->and($media->cover_url)->toStartWith('/covers/');
});

test('mangadex covers are cached and every other source hotlinks', function () {
    $this->seed(ExternalSourceSeeder::class);

    expect(ExternalSource::where('cover_policy', CoverPolicy::Cache)->pluck('key')->all())->toBe(['mangadex']);
});

test('a backdrop of a cover declaring oversized dimensions falls back to the original without decoding it', function () {
    // A valid PNG header declaring 20000x20000 px: tiny in bytes, enormous once decoded.
    $png = substr($this->png, 0, 16).pack('NN', 20000, 20000).substr($this->png, 24);
    Http::fake(['s4.anilist.co/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
    $media = Media::factory()->create(['cover_url' => 'https://s4.anilist.co/file/huge.png']);
    $path = 'covers/'.sha1($media->cover_url);

    $this->actingAs($this->user)->get(route('covers.show', [$media, 'size' => 'backdrop']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    Storage::disk('local')->assertExists($path);
    Storage::disk('local')->assertMissing($path.'-backdrop');
});

test('a backdrop of a cover GD cannot decode falls back to the original', function () {
    // Valid header and dimensions, truncated data: getimagesize accepts it, GD refuses to decode it.
    $png = substr($this->png, 0, 33);
    Http::fake(['s4.anilist.co/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
    $media = Media::factory()->create(['cover_url' => 'https://s4.anilist.co/file/broken.png']);
    $path = 'covers/'.sha1($media->cover_url);

    $this->actingAs($this->user)->get(route('covers.show', [$media, 'size' => 'backdrop']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    Storage::disk('local')->assertMissing($path.'-backdrop');
});

test('a mature cover is refused for a reader without access at the backdrop size too', function () {
    Http::fake(['s4.anilist.co/*' => Http::response($this->png, 200, ['Content-Type' => 'image/png'])]);
    $media = Media::factory()->create(['cover_url' => 'https://s4.anilist.co/file/adult.png', 'age_rating' => AgeRating::Adult]);

    $this->actingAs($this->user)->get(route('covers.show', $media))->assertNotFound();
    $this->actingAs($this->user)->get(route('covers.show', [$media, 'size' => 'backdrop']))->assertNotFound();
    Storage::disk('local')->assertMissing('covers/'.sha1($media->cover_url).'-backdrop');
});
