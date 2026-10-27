<?php

/**
 * A one-word key that names a translation group (`pagination`, `auth`, ...) is read as that whole group, an array, once the file
 * system ignores case (macOS): `__('Pagination')` then breaks the page with "htmlspecialchars(): array given". Linux never shows it.
 */
test('no translation key names a translation group in any letter case', function () {
    $groups = collect(glob(base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/*.php')))
        ->merge(glob(lang_path('en/*.php')) ?: [])
        ->map(fn (string $file): string => strtolower(basename($file, '.php')))
        ->unique()
        ->all();

    $files = collect(['app', 'resources/views'])
        ->flatMap(fn (string $dir) => iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS))))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php');

    $collisions = $files->flatMap(function (SplFileInfo $file) use ($groups): array {
        preg_match_all("/(?:__|trans|trans_choice|@lang)\\(\\s*'([A-Za-z]+)'/", (string) file_get_contents($file->getPathname()), $matches);

        return collect($matches[1])
            ->filter(fn (string $key): bool => in_array(strtolower($key), $groups, true))
            ->map(fn (string $key): string => str_replace(base_path().'/', '', $file->getPathname()).": __('{$key}')")
            ->all();
    })->values()->all();

    expect($collisions)->toBe([]);
});
