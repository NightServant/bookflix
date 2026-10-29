<?php

test('the acceptance harness drives a component through load, a11y, wire:navigate and back', function () {
    $path = componentPage('<x-app-logo href="/_test/component" />');

    assertComponentAcceptance($path, fn ($page) => $page->assertSee('Bookflix'));
});

test('the acceptance harness also runs with reduced motion', function () {
    $path = componentPage('<x-app-logo href="/_test/component" />');

    assertComponentAcceptance($path, options: ['reducedMotion' => 'reduce']);
});
