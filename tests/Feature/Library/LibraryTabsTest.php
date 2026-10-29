<?php

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

/**
 * Register the pages that sit under the library, as their tasks will.
 *
 * @param  list<string>  $names
 */
function registerLibrarySections(array $names): void
{
    foreach ($names as $name) {
        Route::middleware('web')->get('/_test/'.$name, fn () => '')->name($name);
    }

    app('router')->getRoutes()->refreshNameLookups();
}

test('the library alone has no tabs to switch between', function () {
    // Every section is a real page by now, so strip the router down to the library's own route.
    $routes = app('router')->getRoutes();
    $alone = new RouteCollection;
    $alone->add($routes->getByName('library.index'));
    $alone->refreshNameLookups();
    app('router')->setRoutes($alone);

    try {
        expect(htmlQuery(renderedComponent('<x-library-tabs current="library.index" />'), '//nav')->length)->toBe(0);
    } finally {
        app('router')->setRoutes($routes);
    }
});

test('the sections that exist are tabs under the library title, and the current one says so', function () {
    registerLibrarySections(['collections.index', 'history.index']);

    $html = renderedComponent('<x-library-tabs current="collections.index" />');

    expect(collect(htmlQuery($html, '//nav[@aria-label="Library sections"]//a'))->map(fn (DOMElement $link): string => trim($link->textContent))->all())->toBe(['Library', 'Collections', 'History'])
        ->and(htmlQuery($html, '//nav//a[@aria-current="page"]')->length)->toBe(1)
        ->and(trim(htmlQuery($html, '//nav//a[@aria-current="page"]')->item(0)->textContent))->toBe('Collections')
        ->and(htmlQuery($html, '//nav//a[contains(@class,"min-h-11")]')->length)->toBe(3)
        ->and(htmlQuery($html, '//nav//a[@href="'.route('library.index').'"]')->length)->toBe(1);
});

test('a section whose page is not built yet is left out', function () {
    registerLibrarySections(['collections.index']);

    $html = renderedComponent('<x-library-tabs current="library.index" />');

    expect(collect(htmlQuery($html, '//nav//a'))->map(fn (DOMElement $link): string => trim($link->textContent))->all())->toBe(['Library', 'Collections']);
});
