<?php

function renderCombobox(string $attributes = ''): string
{
    return renderedComponent("<x-combobox label=\"Genre\" :options=\"['fantasy' => 'Fantasy', 'sci-fi' => 'Science fiction']\" {$attributes} />");
}

test('a combobox has a real label, a text input, a toggle button and a listbox of options', function () {
    $html = renderCombobox('placeholder="Pick a genre"');

    expect(htmlQuery($html, '//label[@*[name()="x-combobox:label"]][normalize-space()="Genre"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//input[@*[name()="x-combobox:input"]][@placeholder="Pick a genre"][@autocomplete="off"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//button[@*[name()="x-combobox:button"]][@aria-label="Show options"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//ul[@*[name()="x-combobox:options"]]//li[@*[name()="x-combobox:option"]]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@x-data][@x-combobox]')->length)->toBe(0);
});

test('options are a value to label map, or a plain list that is its own value', function () {
    $decoded = function (string $html): array {
        preg_match("/JSON\.parse\('(.*)'\)/", htmlQuery($html, '//*[@x-data]')->item(0)->getAttribute('x-data'), $json);

        return json_decode(str_replace(chr(92).'u0022', '"', $json[1]), true);
    };

    expect($decoded(renderCombobox()))->toBe([['value' => 'fantasy', 'label' => 'Fantasy'], ['value' => 'sci-fi', 'label' => 'Science fiction']])
        ->and($decoded(renderedComponent('<x-combobox label="Language" :options="[\'English\', \'Japanese\']" />')))->toBe([['value' => 'English', 'label' => 'English'], ['value' => 'Japanese', 'label' => 'Japanese']]);
});

test('multiple mode adds the multiple flag and a list of removable chips', function () {
    $single = renderCombobox();
    $multiple = renderCombobox('multiple');

    expect(htmlQuery($single, '//*[@x-combobox][@multiple]')->length)->toBe(0)
        ->and(htmlQuery($single, '//button[@*[name()=":aria-label"]]')->length)->toBe(0)
        ->and(htmlQuery($multiple, '//*[@x-combobox][@multiple]')->length)->toBe(1)
        ->and(htmlQuery($multiple, '//ul[@aria-label="Selected"]//template[@x-for]//li[contains(@class,"rounded-full")]')->length)->toBe(1)
        ->and(htmlQuery($multiple, '//li//button[@type="button"][contains(@*[name()=":aria-label"],"Remove")][contains(@class,"size-9")]')->length)->toBe(1);
});

test('model attributes land on the combobox element and the rest on the wrapper', function () {
    $html = renderCombobox('wire:model="genre" class="mt-4" data-test="genres"');

    expect(htmlQuery($html, '//*[@x-combobox][@*[name()="wire:model"]="genre"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@x-data][@data-test="genres"][contains(@class,"mt-4")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@x-combobox][@data-test]')->length)->toBe(0);
});

test('the input and toggle are 44px targets with the token radius, the popup is a card', function () {
    $html = renderCombobox();

    expect($html)->toContain('h-11!')->toContain('size-11')->toContain('focus-ring')
        ->and(htmlQuery($html, '//ul[@*[name()="x-combobox:options"]][contains(@class,"rounded-card")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//li[@*[name()="x-combobox:option"]][contains(@class,"min-h-11")]')->length)->toBe(1);
});

test('an empty result is announced outside the listbox', function () {
    expect(htmlQuery(renderCombobox(), '//p[@role="status"][normalize-space()="No matches"]')->length)->toBe(1);
});
