<?php

function renderAccordion(string $attributes = 'title="Why is this classified here?"', string $slot = '<p>Because of its subject.</p>'): string
{
    return renderedComponent("<x-accordion {$attributes}>{$slot}</x-accordion>");
}

test('an accordion is a details with a summary title and a collapsing panel', function () {
    $html = renderAccordion();

    expect(htmlQuery($html, '//details/summary//span[normalize-space()="Why is this classified here?"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//details//div[@x-collapse][@x-show="expanded"]/p[normalize-space()="Because of its subject."]')->length)->toBe(1)
        ->and(htmlQuery($html, '//details[@open]')->length)->toBe(0)
        ->and(htmlQuery($html, '//details[@name]')->length)->toBe(0);
});

test('a group name is passed to details so siblings are exclusive', function () {
    $first = renderAccordion('title="One" name="faq"');
    $second = renderAccordion('title="Two" name="faq"');

    expect(htmlQuery($first, '//details[@name="faq"]')->length)->toBe(1)
        ->and(htmlQuery($second, '//details[@name="faq"]')->length)->toBe(1);
});

test('open starts it open with the chevron turned', function () {
    $html = renderAccordion('title="Open one" open');

    expect(htmlQuery($html, '//details[@open]')->length)->toBe(1)
        ->and($html)->toContain('accordion(true)')->toContain('rotate-180');
    expect(renderAccordion())->toContain('accordion(false)');
});

test('the summary is a 44px focusable row, and the chevron animates only with motion allowed', function () {
    $html = renderAccordion();

    expect($html)->toContain('min-h-11')->toContain('focus-ring')->toContain('motion-safe:transition-transform')
        ->and($html)->toContain('motion-reduce:transition-none!');
});
