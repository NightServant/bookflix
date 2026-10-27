<?php

use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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
        ->assertHeader('Cache-Control', 'immutable, max-age=86400, public');

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

test('guests are redirected to login', function () {
    Http::fake();
    $media = Media::factory()->create(['cover_url' => 'https://s4.anilist.co/file/cover.png']);

    $this->get(route('covers.show', $media))->assertRedirect(route('login'));

    Http::assertNothingSent();
});
