<?php

use App\Models\ContentSource;
use App\Models\User;
use App\Reader\ContentUrl;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;

/*
 * The page-wide Content Security Policy (P9-R9): the header, a nonce per request that the head snippet and the Livewire and Flux script tags carry,
 * the switches, and the file stream keeping its own stricter policy.
 */

function nonceOf(string $policy): string
{
    preg_match("/script-src [^;]*'nonce-([^']+)'/", $policy, $match);

    return $match[1] ?? '';
}

beforeEach(function () {
    $this->reader = User::factory()->create();
});

test('a page carries the policy with a nonce that changes per request', function () {
    $first = $this->actingAs($this->reader)->get(route('search'))->assertOk()->headers->get('Content-Security-Policy');
    $second = $this->actingAs($this->reader)->get(route('search'))->assertOk()->headers->get('Content-Security-Policy');

    expect(nonceOf($first))->not->toBe('')->and(nonceOf($first))->not->toBe(nonceOf($second));
});

test('the policy is exactly the one settled', function () {
    $policy = $this->get(route('login'))->headers->get('Content-Security-Policy');
    $nonce = nonceOf($policy);

    expect($policy)->toBe("default-src 'self'; script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; media-src 'self'; worker-src 'self' blob:; frame-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
});

test('scripts never allow unsafe-inline and the page cannot be framed', function () {
    $policy = $this->get(route('login'))->headers->get('Content-Security-Policy');
    preg_match('/script-src [^;]*/', $policy, $script);

    expect($script[0])->not->toContain('unsafe-inline')->and($policy)->toContain("frame-ancestors 'none'");
});

test('the head snippet and the livewire and flux script tags carry this requests nonce', function () {
    $response = $this->actingAs($this->reader)->get(route('search'))->assertOk();
    $nonce = nonceOf($response->headers->get('Content-Security-Policy'));
    $html = $response->getContent();

    expect($html)->toContain("<script nonce=\"{$nonce}\">window.localStorage.setItem('flux.appearance'");

    preg_match_all('/<script\b([^>]*)>/i', $html, $tags);
    $executable = array_filter($tags[1], fn (string $attributes) => ! str_contains($attributes, 'application/json') && ! str_contains($attributes, 'application/ld+json'));
    // Livewire keeps `@assets` tags between requests inside one test process, so a script a previous test's login page queued can show up here with that request's nonce.
    $executable = array_filter($executable, fn (string $attributes) => ! str_contains($attributes, 'passkeys-'));

    expect($executable)->not->toBeEmpty();

    foreach ($executable as $attributes) {
        expect($attributes)->toContain("nonce=\"{$nonce}\"");
    }
});

test('the policy can be switched off', function () {
    config(['bookflix.csp.enabled' => false]);

    $response = $this->get(route('login'))->assertOk();

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

test('report only sends the report only header instead', function () {
    config(['bookflix.csp.report_only' => true]);

    $response = $this->get(route('login'))->assertOk();

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))->toContain("frame-ancestors 'none'");
});

test('the file stream keeps its own policy', function () {
    Storage::fake(ContentSource::disk());
    $source = ContentSource::factory()->publicDomain()->verified()->ready()->create();

    $response = $this->actingAs($this->reader)->get(ContentUrl::for($this->reader, $source))->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
        ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

test('the dev server origin is allowed while vite runs hot', function () {
    $hot = storage_path('framework/vite-test.hot');
    file_put_contents($hot, 'http://localhost:5173');
    Vite::useHotFile($hot);

    try {
        $policy = $this->get(route('login'))->headers->get('Content-Security-Policy');
    } finally {
        @unlink($hot);
    }

    expect($policy)->toContain("connect-src 'self' http://localhost:5173 ws://localhost:5173")->toContain("font-src 'self' data: http://localhost:5173");
});
