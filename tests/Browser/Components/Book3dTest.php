<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

beforeEach(function () {
    View::addLocation(base_path('tests/Fixtures/views'));

    Route::middleware('web')->group(function () {
        Route::get('/_test/cover/{n}', fn (int $n) => response()->file(base_path("tests/Fixtures/covers/{$n}.jpg")))->whereNumber('n');
        Route::get('/_test/books', fn () => view('book-fixture', ['covers' => array_map(fn ($n) => "/_test/cover/{$n}", [1, 2, 3, 4]), 'scene' => (bool) request('scene')]));
        Route::get('/_test/elsewhere', fn () => '<!DOCTYPE html><html><body><a href="/_test/books" wire:navigate>Back to books</a></body></html>');
    });

    $this->path = '/_test/books';
});

test('books render at every size with their cover, and a missing or broken cover shows the fallback', function () {
    visit($this->path)->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertScript('[...document.querySelectorAll("[data-test=sizes] [data-book-3d]")].map((b) => Math.round(b.getBoundingClientRect().width)).join()', '80,128,192,272')
        ->assertScript('getComputedStyle(document.querySelector("[data-test=book-missing] [data-book-fallback]")).display', 'block')
        ->assertScript('getComputedStyle(document.querySelector("[data-test=book-broken] [data-book-fallback]")).display', 'block')
        ->assertScript('getComputedStyle(document.querySelector("[data-test=book-lg] [data-book-fallback]")).display', 'none');
});

test('an interactive book leans towards the pointer, within 10 degrees', function () {
    visit($this->path)->wait(1)
        ->hover('@book-interactive')
        ->wait(0.6)
        ->assertScript('document.querySelector("[data-test=book-interactive]").style.getPropertyValue("--px") !== ""', true)
        ->assertScript('Math.abs(Number(document.querySelector("[data-test=book-interactive]").style.getPropertyValue("--py"))) <= 1', true);
});

test('with reduced motion a book keeps its resting angle: no lean, no transition, no running animation', function () {
    visit($this->path, ['reducedMotion' => 'reduce'])->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->hover('@book-interactive')
        ->wait(0.6)
        ->assertScript('document.querySelector("[data-test=book-interactive]").style.getPropertyValue("--px")', '')
        ->assertScript('getComputedStyle(document.querySelector("[data-test=book-interactive] .book3d__body")).transitionDuration', '0s')
        ->assertScript('(() => { const m = new DOMMatrix(getComputedStyle(document.querySelector("[data-test=book-interactive] .book3d__body")).transform); return m.m13 === 0 && Math.abs(m.m23 + 0.1219) < 0.001; })()', true)
        ->assertScript('document.getAnimations().length', 0);
});

test('books survive a wire:navigate round trip', function () {
    visit($this->path)->wait(1)
        ->click('Go elsewhere')->assertSee('Back to books')
        ->click('Back to books')->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertScript('document.querySelectorAll("[data-book-3d]").length', 8);
});
