<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

beforeEach(function () {
    View::addLocation(base_path('tests/Fixtures/views'));

    Route::middleware('web')->group(function () {
        Route::get('/_test/cover/{n}', fn (int $n) => response()->file(base_path("tests/Fixtures/covers/{$n}.jpg")))->whereNumber('n');
        Route::get('/_test/books', fn () => view('book-fixture', ['covers' => array_map(fn ($n) => "/_test/cover/{$n}", [1, 2, 3, 4]), 'scene' => request('scene', '')]));
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

test('the cover wraps the whole jacket: the spine and the back use it, and a missing cover has no image on either', function () {
    $face = fn (string $book, string $face, string $property = 'backgroundImage') => 'getComputedStyle(document.querySelector("[data-test='.$book.'] .book3d__'.$face.'"), "::before").'.$property;

    visit($this->path)->wait(1)
        ->assertScript($face('book-interactive', 'spine').'.includes("/_test/cover/3")', true)
        ->assertScript($face('book-interactive', 'back').'.includes("/_test/cover/3")', true)
        ->assertScript($face('book-interactive', 'spine', 'transform'), 'none') // never a mirrored copy of the cover's letters
        ->assertScript('parseFloat('.$face('book-interactive', 'spine', 'filter').'.match(/blur\\(([\\d.]+)px/)[1]) > 8', true) // blurred into colour
        ->assertScript('parseFloat('.$face('book-interactive', 'back', 'filter').'.match(/blur\\(([\\d.]+)px/)[1]) > 15', true) // the back's title is not readable
        ->assertScript($face('book-missing', 'spine', 'display'), 'none')
        ->assertScript($face('book-broken', 'back', 'display'), 'none');
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
        ->assertScript('document.querySelectorAll("[data-book-3d]").length', 9);
});

test('with motion allowed a canvas fades in over the CSS book, which then steps aside, and three.js arrives as its own chunk', function () {
    visit($this->path.'?scene=1')
        ->assertScript('performance.getEntriesByType("resource").some((r) => r.name.includes("three.module"))', false)
        ->wait(4)
        ->assertNoJavaScriptErrors()
        ->assertScript('document.querySelectorAll("canvas.book3d__canvas[data-ready]").length', 1)
        ->assertScript('document.querySelector("[data-test=book-xl]").dataset.scene', 'ready')
        ->assertScript('document.querySelectorAll("canvas").length', 1)
        ->assertScript('document.querySelector("[data-test=book-xl]").hasAttribute("data-scene-live")', true)
        ->assertScript('getComputedStyle(document.querySelector("[data-test=book-xl] .book3d__body")).visibility', 'hidden')
        ->assertScript('getComputedStyle(document.querySelector("[data-test=book-xl] canvas")).opacity', '1')
        ->assertScript('performance.getEntriesByType("resource").some((r) => r.name.includes("three.module"))', true);
});

test('with reduced motion there is no canvas, no three.js, and no running animation', function () {
    visit($this->path.'?scene=1', ['reducedMotion' => 'reduce'])->wait(3)
        ->assertNoJavaScriptErrors()
        ->assertScript('document.querySelectorAll("canvas").length', 0)
        ->assertScript('document.querySelector("[data-test=book-xl]").dataset.scene', 'skipped')
        ->assertScript('getComputedStyle(document.querySelector("[data-test=book-xl] .book3d__body")).visibility', 'visible')
        ->assertScript('performance.getEntriesByType("resource").some((r) => r.name.includes("three.module"))', false)
        ->assertScript('document.getAnimations().length', 0);
});

test('navigating away disposes the scene: the canvas is gone and its WebGL context is released', function () {
    $page = visit($this->path.'?scene=1')->wait(4)
        ->assertScript('document.querySelectorAll("canvas").length > 0', true);

    $page->script('window.__canvas = document.querySelector("[data-test=book-xl] canvas"); window.__host = document.querySelector("[data-test=book-xl]"); window.__gl = window.__canvas.getContext("webgl2") ?? window.__canvas.getContext("webgl");');
    $page->click('Go elsewhere')->assertSee('Back to books')
        ->assertScript('window.__canvas.isConnected', false)
        ->assertScript('window.__gl.isContextLost()', true)
        ->assertScript('window.__host.dataset.scene', 'disposed')
        ->assertScript('document.querySelectorAll("canvas").length', 0);
});
