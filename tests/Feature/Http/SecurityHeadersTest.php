<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('security headers are present', function () {
    $this->get(route('landing'))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
});

test('no page sends its address to another site, so hotlink-protected covers (MangaDex) are served, not their placeholder', function () {
    $this->get(route('landing'))->assertHeader('Referrer-Policy', 'same-origin');
});

test('headers and request id also cover the deactivated-account redirect', function () {
    $response = $this->actingAs(User::factory()->deactivated()->create())->get(route('landing'));

    $response->assertRedirect(route('login'))->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Request-Id');
});

test('headers and request id cover guest redirects and 404s', function () {
    $this->get(route('home'))
        ->assertRedirect(route('login'))
        ->assertHeader('X-Request-Id')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertHeader('X-Request-Id')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('every header carries its exact value, and HSTS has no preload', function () {
    $headers = $this->get('https://localhost/up')->headers;

    expect($headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($headers->get('Referrer-Policy'))->toBe('same-origin')
        ->and($headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($headers->get('Permissions-Policy'))->toBe('camera=(), microphone=(), geolocation=(), payment=(), usb=()')
        ->and($headers->get('Strict-Transport-Security'))->toBe('max-age=63072000; includeSubDomains')
        ->and($headers->get('Strict-Transport-Security'))->not->toContain('preload');
});

test('secure responses tell browsers to stay on https', function () {
    $this->get('https://localhost/up')->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
});

test('secure error and redirect responses carry it too', function () {
    $this->get('https://localhost/no-such-page')->assertNotFound()->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
    $this->get('https://localhost/home')->assertRedirect()->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
});

test('a local environment never pins its host, even over https', function () {
    $original = app()->environment();
    app()->detectEnvironment(fn () => 'local');

    try {
        $this->get('https://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
    } finally {
        app()->detectEnvironment(fn () => $original);
    }
});

test('plain http responses carry no HSTS header', function () {
    $this->get('http://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
    $this->get('http://localhost/no-such-page')->assertHeaderMissing('Strict-Transport-Security');
});

test('the private disk is never served over http', function () {
    expect(Route::has('storage.local'))->toBeFalse();
});

test('an uploaded SVG never gets a preview url', function () {
    expect(config('livewire.temporary_file_upload.preview_mimes'))->not->toContain('svg');
});
