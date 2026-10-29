<?php

function renderStepper(int $current = 2, string $attributes = ''): string
{
    return renderedComponent("<x-stepper :steps=\"['Types', 'Genres', 'Stories']\" :current=\"{$current}\" {$attributes} />");
}

function stepperText(string $html, string $xpath): array
{
    return collect(htmlQuery($html, $xpath))->map(fn ($node) => preg_replace('/\s+/', ' ', trim($node->textContent)))->all();
}

test('the steps are an ordered list inside a named navigation landmark', function () {
    $html = renderStepper();

    expect(htmlQuery($html, '//nav[@aria-label="Steps"]/ol/li')->length)->toBe(3)
        ->and(htmlQuery(renderStepper(attributes: 'label="Onboarding steps"'), '//nav[@aria-label="Onboarding steps"]')->length)->toBe(1);
});

test('each step is numbered 01, 02, 03 above its name, in the eyebrow and title recipe', function () {
    $html = renderStepper();

    expect(stepperText($html, '//li//span[@aria-hidden="true"]'))->toBe(['01 Types', '02 Genres', '03 Stories'])
        ->and(stepperText($html, '//li//*[@data-stepper-number]'))->toBe(['01', '02', '03'])
        ->and(htmlQuery($html, '//*[@data-stepper-number][contains(@class,"text-legal")][contains(@class,"uppercase")][contains(@class,"tracking-widest")][contains(@class,"tabular-nums")]')->length)->toBe(3);
});

test('the current step is announced with aria-current and its number', function () {
    $html = renderStepper(current: 2);

    expect(htmlQuery($html, '//li[@aria-current="step"]')->length)->toBe(1)
        ->and(stepperText($html, '//li[@aria-current="step"]//*[contains(@class,"sr-only")]'))->toBe(['Step 2 of 3: Genres'])
        ->and(stepperText($html, '//li[not(@aria-current)]//*[contains(@class,"sr-only")]'))->toBe(['Step 1 of 3: Types (done)', 'Step 3 of 3: Stories'])
        ->and(stepperText(renderStepper(current: 2, attributes: 'action="goTo"'), '//li[not(@aria-current)]//*[contains(@class,"sr-only")]'))->toBe(['Go back to step 1 of 3: Types', 'Step 3 of 3: Stories']);

    expect(htmlQuery(renderStepper(current: 1), '//li[@aria-current="step"]')->item(0)->textContent)->toContain('Types')
        ->and(htmlQuery(renderStepper(current: 3), '//li[@aria-current="step"]')->item(0)->textContent)->toContain('Stories');
});

test('steps before the current one are buttons that go back when an action is given, and later steps never are', function () {
    $plain = renderStepper(current: 3);
    $linked = renderStepper(current: 3, attributes: 'action="goTo"');

    expect(htmlQuery($plain, '//button')->length)->toBe(0)
        ->and(htmlQuery($linked, '//li//button[@type="button"][@*[name()="wire:click"]="goTo(1)"]')->length)->toBe(1)
        ->and(htmlQuery($linked, '//li//button[@*[name()="wire:click"]="goTo(2)"]')->length)->toBe(1)
        ->and(htmlQuery($linked, '//button[@*[name()="wire:click"]="goTo(3)"]')->length)->toBe(0)
        ->and(htmlQuery(renderStepper(current: 1, attributes: 'action="goTo"'), '//button')->length)->toBe(0);
});

test('a step that can be reached is a 44px target with the shared focus ring', function () {
    $html = renderStepper(current: 3, attributes: 'action="goTo"');

    expect(htmlQuery($html, '//button[contains(@class,"min-h-11")][contains(@class,"focus-ring")]')->length)->toBe(2);
});

test('the done steps show a check, and the ones to come only hairlines', function () {
    $html = renderStepper(current: 3);

    expect(htmlQuery($html, '//li[position() < 3]//svg[@aria-hidden="true"]')->length)->toBe(2)
        ->and(htmlQuery($html, '//li[3]//svg')->length)->toBe(0);
});

test('nothing in the stepper animates outside motion-safe', function () {
    $source = file_get_contents(resource_path('views/components/stepper.blade.php'));

    expect(preg_match_all('/(?<![\w-])(?:transition|duration-|animate-|ease-)[\w\[\]()-]*/', $source, $matches))->toBeGreaterThan(0);

    foreach ($matches[0] as $class) {
        expect($source)->toContain("motion-safe:{$class}");
    }
});
