<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * The desktop header navbar and the mobile bottom nav markup of a rendered page.
 *
 * @return array{header: string, bottom: string}
 */
function primaryNavs(string $html): array
{
    preg_match('#<nav[^>]*data-flux-navbar>.*?</nav>#s', $html, $header);
    preg_match('#<nav aria-label="Primary".*?</nav>#s', $html, $bottom);

    return ['header' => $header[0] ?? '', 'bottom' => $bottom[0] ?? ''];
}

/**
 * A provider fixture recorded from the real API (Pest already owns `fixture()`, which returns a path), e.g. `openlibrary/work-dune.json`.
 *
 * @return array<string, mixed>
 */
function providerFixture(string $path): array
{
    return json_decode(file_get_contents(__DIR__."/Fixtures/providers/{$path}"), true, flags: JSON_THROW_ON_ERROR);
}
