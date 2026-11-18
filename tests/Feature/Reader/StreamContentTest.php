<?php

use App\Models\ContentSource;
use App\Models\User;
use App\Reader\ContentUrl;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/*
 * The signed, user-bound file route (P9-R8) and the admin preview. The file is 1,000 bytes of a known pattern, so every range can be checked byte for byte.
 */

beforeEach(function () {
    Storage::fake(ContentSource::disk());

    $this->body = implode('', array_map(fn (int $i) => chr(65 + ($i % 26)), range(0, 999)));
    $this->reader = User::factory()->create();
    $this->source = ContentSource::factory()->publicDomain()->verified()->ready()->create(['storage_path' => 'content/test/book', 'byte_size' => 1000]);
    Storage::disk(ContentSource::disk())->put('content/test/book', $this->body);
    $this->url = ContentUrl::for($this->reader, $this->source);
    $this->stream = fn (string $range, ?string $url = null) => $this->actingAs($this->reader)->get($url ?? $this->url, ['Range' => $range]);
});

test('a valid signed url streams the whole file with the right headers', function () {
    $response = $this->actingAs($this->reader)->get($this->url);

    $response->assertOk()
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Type', 'application/epub+zip')
        ->assertHeader('Content-Disposition', 'inline')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->streamedContent())->toBe($this->body);
});

test('the type comes from the stored format, not the file name', function () {
    $source = ContentSource::factory()->publicDomain()->verified()->ready()->create(['storage_path' => 'content/test/evil.html', 'byte_size' => 5, 'format' => 'pdf']);
    Storage::disk(ContentSource::disk())->put('content/test/evil.html', '<b>x</b>');

    $this->actingAs($this->reader)->get(ContentUrl::for($this->reader, $source))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('a range returns 206 and the exact bytes', function () {
    $response = ($this->stream)('bytes=10-19');

    $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 10-19/1000')->assertHeader('Content-Length', '10');
    expect($response->streamedContent())->toBe(substr($this->body, 10, 10));
});

test('an open-ended range reads to the end', function () {
    $response = ($this->stream)('bytes=100-');

    $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 100-999/1000');
    expect($response->streamedContent())->toBe(substr($this->body, 100));
});

test('a suffix range reads the last bytes', function () {
    $response = ($this->stream)('bytes=-50');

    $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 950-999/1000');
    expect($response->streamedContent())->toBe(substr($this->body, -50));
});

test('an end past the file is cut to the end', function () {
    $response = ($this->stream)('bytes=990-5000');

    $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 990-999/1000');
    expect($response->streamedContent())->toBe(substr($this->body, 990));
});

test('several ranges get the whole file with 200', function () {
    $response = ($this->stream)('bytes=0-9, 20-29');

    $response->assertOk()->assertHeaderMissing('Content-Range');
    expect($response->streamedContent())->toBe($this->body);
});

test('a malformed or out of bounds range answers 416 with the size', function (string $range) {
    ($this->stream)($range)->assertStatus(416)->assertHeader('Content-Range', 'bytes */1000');
})->with(['bytes=abc', 'bytes=-', 'bytes=20-10', 'bytes=1000-', 'bytes=5000-6000', 'bytes=-0', 'items=0-5', 'bytes=0-5,x']);

test('an expired url is refused', function () {
    $url = URL::temporarySignedRoute('reader.stream', now()->subMinute(), ['source' => $this->source->id, 'user' => $this->reader->id], absolute: false);

    $this->actingAs($this->reader)->get($url)->assertForbidden();
});

test('a tampered signature is refused', function () {
    $this->actingAs($this->reader)->get($this->url.'0')->assertForbidden();
    $this->actingAs($this->reader)->get(str_replace('user='.$this->reader->id, 'user=999', $this->url))->assertForbidden();
});

test('another reader with a valid url is refused', function () {
    $this->actingAs(User::factory()->create())->get($this->url)->assertForbidden();
});

test('an unsigned address is refused', function () {
    $this->actingAs($this->reader)->get(route('reader.stream', ['source' => $this->source->id, 'user' => $this->reader->id]))->assertForbidden();
});

test('a guest is sent to sign in', function () {
    $this->get($this->url)->assertRedirect(route('login'));
});

test('a source unverified after the url was issued is refused', function () {
    $this->source->update(['verified_by' => null, 'verified_at' => null]);

    $this->actingAs($this->reader)->get($this->url)->assertForbidden();
});

test('a file deleted from the disk answers 404 with a plain body', function () {
    Storage::disk(ContentSource::disk())->delete('content/test/book');

    $response = $this->actingAs($this->reader)->get($this->url)->assertNotFound();

    expect($response->headers->get('Content-Type'))->toContain('text/plain')
        ->and($response->getContent())->toBe('This file is no longer available.');
});

test('an id that does not exist answers 404', function () {
    $url = URL::temporarySignedRoute('reader.stream', now()->addMinutes(5), ['source' => 999999, 'user' => $this->reader->id], absolute: false);

    $this->actingAs($this->reader)->get($url)->assertNotFound();
});

test('a head request has the headers and no body', function () {
    $response = $this->actingAs($this->reader)->call('HEAD', $this->url);

    $response->assertOk()->assertHeader('Accept-Ranges', 'bytes')->assertHeader('Content-Length', '1000');
    expect($response->getContent())->toBeIn(['', false, null]);
});

test('an admin cannot use the reader route', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(ContentUrl::for($admin, $this->source))->assertRedirect(route('admin.index'));
});

test('the url is signed relative and lasts the configured minutes', function () {
    config(['bookflix.reader.url_ttl_minutes' => 10]);
    $url = ContentUrl::for($this->reader, $this->source);

    expect($url)->toStartWith('/reader/stream/'.$this->source->id.'?')->and($url)->not->toContain('http');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    expect((int) $query['expires'])->toBe(now()->addMinutes(10)->getTimestamp())->and($query['user'])->toBe((string) $this->reader->id);
});

describe('admin preview', function () {
    beforeEach(function () {
        $this->admin = User::factory()->admin()->create();
        $this->unverified = ContentSource::factory()->publicDomain()->ready()->create(['storage_path' => 'content/test/book', 'byte_size' => 1000]);
        $this->preview = fn (ContentSource $source, ?User $for = null) => ContentUrl::preview($for ?? $this->admin, $source);
    });

    test('an admin previews an unverified file with the same response and ranges', function () {
        $response = $this->actingAs($this->admin)->get(($this->preview)($this->unverified), ['Range' => 'bytes=0-4']);

        $response->assertStatus(206)->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")->assertHeader('Content-Disposition', 'inline');
        expect($response->streamedContent())->toBe(substr($this->body, 0, 5));
    });

    test('a reader upload is never previewed', function () {
        $owner = User::factory()->create();
        $upload = ContentSource::factory()->userOwned($owner)->ready()->create();

        $this->actingAs($this->admin)->get(($this->preview)($upload))->assertForbidden();
    });

    test('a source with no file is not previewed', function () {
        $pending = ContentSource::factory()->publicDomain()->create();

        $this->actingAs($this->admin)->get(($this->preview)($pending))->assertForbidden();
    });

    test('readers are refused', function () {
        $this->actingAs($this->reader)->get(($this->preview)($this->unverified))->assertForbidden();
    });

    test('an address signed for one admin is refused for another, and one without a user is refused', function () {
        $other = User::factory()->admin()->create();
        $unbound = URL::temporarySignedRoute('admin.content.preview', now()->addMinutes(10), ['source' => $this->unverified->id], absolute: false);

        $this->actingAs($other)->get(($this->preview)($this->unverified))->assertForbidden();
        $this->actingAs($this->admin)->get($unbound)->assertForbidden();
        $this->actingAs($this->admin)->get(($this->preview)($this->unverified, $other))->assertForbidden();
        $this->actingAs($this->admin)->get(($this->preview)($this->unverified))->assertSuccessful();
    });

    test('an unsigned address is refused', function () {
        $this->actingAs($this->admin)->get('/admin/content/'.$this->unverified->id.'/preview')->assertForbidden();
    });
});
