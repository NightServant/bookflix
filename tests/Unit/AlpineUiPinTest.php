<?php

test('@alpinejs/ui stays on the version whose private internals the app reads', function () {
    $lock = json_decode((string) file_get_contents(__DIR__.'/../../package-lock.json'), true, flags: JSON_THROW_ON_ERROR);

    $this->assertSame(
        '3.17.5',
        $lock['packages']['node_modules/@alpinejs/ui']['version'] ?? null,
        '@alpinejs/ui changed from 3.17.5. The app reads its private internals, which only tests/Browser exercises: '
        .'__value, __context, __isOpen, __close, $refs.__input and $refs.__options (resources/js/components.js, the combobox) and __buttonEl '
        .'(resources/views/components/story-previews.blade.php, `$data.__buttonEl`). Re-verify them against the new version (also after a livewire/livewire bump, '
        .'which bundles Alpine core), run vendor/bin/pest tests/Browser, then update the version in this test.',
    );
});

test("Livewire's bundled Alpine stays on the version whose internals @alpinejs/ui and the app rely on", function () {
    $lock = json_decode((string) file_get_contents(__DIR__.'/../../composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    $livewire = collect($lock['packages'])->firstWhere('name', 'livewire/livewire');
    $message = 'livewire/livewire changed from v4.4.8 (it bundles Alpine core 3.17.5, the version @alpinejs/ui is pinned to). Re-verify the private internals '
        .'listed in the @alpinejs/ui test above against the new bundle, run vendor/bin/pest tests/Browser, then update the versions in this test.';

    $this->assertSame('v4.4.8', $livewire['version'] ?? null, $message);
    $this->assertStringContainsString('version: "3.17.5"', (string) file_get_contents(__DIR__.'/../../vendor/livewire/livewire/dist/livewire.js'), $message);
});
