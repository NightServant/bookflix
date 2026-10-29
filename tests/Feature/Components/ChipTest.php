<?php

test('a chip is a 36px pill that keeps a 44px target through its pseudo-element', function () {
    $html = renderedComponent('<x-chip href="/search">Fantasy</x-chip>');

    expect(htmlQuery($html, '//a[@href="/search"][contains(@class,"h-9")][contains(@class,"after:-inset-y-1")][contains(@class,"text-meta")]')->length)->toBe(1)
        ->and($html)->not->toContain('aria-current');
});

test('an active chip is filled with ink and marks itself current', function () {
    $html = renderedComponent('<x-chip href="/search" :active="true">Fantasy</x-chip>');

    expect(htmlQuery($html, '//a[@aria-current="true"][contains(@class,"bg-ink")]')->length)->toBe(1);
});
