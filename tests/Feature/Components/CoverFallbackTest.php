<?php

test('a cover fallback sets the title in the display face on the card surface and hides from screen readers', function () {
    $html = renderedComponent('<x-cover-fallback title="The Gentlemen’s Alliance" />');

    expect(htmlQuery($html, '//span[@data-cover-fallback][@aria-hidden="true"][contains(@class,"bg-surface-card")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//span[contains(@class,"font-black")][contains(@class,"line-clamp-6")]')->item(0)->textContent)->toBe('The Gentlemen’s Alliance')
        ->and(htmlQuery($html, '//img | //svg')->length)->toBe(0);
});

test('a compact fallback sets only the first letter, uppercased', function () {
    $html = renderedComponent('<x-cover-fallback title="  éclair" compact />');

    expect(htmlQuery($html, '//span[contains(@class,"font-black")]')->item(0)->textContent)->toBe('É');
});
