<?php

test('@alpinejs/ui stays on the version whose private internals the app reads', function () {
    $lock = json_decode((string) file_get_contents(__DIR__.'/../../package-lock.json'), true, flags: JSON_THROW_ON_ERROR);

    $this->assertSame(
        '3.17.5',
        $lock['packages']['node_modules/@alpinejs/ui']['version'] ?? null,
        '@alpinejs/ui changed from 3.17.5. The app reads its private internals, which only tests/Browser exercises: '
        .'__value, __context, __isOpen, __close, $refs.__input and $refs.__options (resources/js/components.js, the combobox) and __buttonEl '
        .'(resources/views/components/story-preview.blade.php). Re-verify them against the new version (also after a livewire/livewire bump, '
        .'which bundles Alpine core), run vendor/bin/pest tests/Browser, then update the version in this test.',
    );
});
