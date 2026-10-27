<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Component kit source rules (shared by every component)
|--------------------------------------------------------------------------
|
| Greps the component views, so a new component is covered the moment it lands.
|
*/

/**
 * @return Collection<int, SplFileInfo>
 */
function componentViews(): Collection
{
    return collect(File::allFiles(resource_path('views/components')));
}

test('component views use design tokens and no raw colors', function () {
    $offenders = componentViews()
        ->filter(fn ($f) => preg_match('/#[0-9a-fA-F]{3,8}\b|rgb\(|hsl\(/', $f->getContents()))
        ->map->getFilename();

    expect($offenders)->toBeEmpty();
});

test('component views draw no shadows beyond the three elevation tokens', function () {
    $offenders = componentViews()
        ->filter(fn ($f) => preg_match('/\b(?:inset-)?shadow-(?!none|raised|overlay|glow-brand)|drop-shadow/', $f->getContents()))
        ->map->getFilename();

    expect($offenders)->toBeEmpty();
});

test('only chips, markers and thumbs are fully rounded', function () {
    // Decision (c): add a component here only when it is a chip, a step marker or a slider thumb.
    $allowed = ['chip.blade.php', 'combobox.blade.php', 'result-row.blade.php'];

    $offenders = componentViews()
        ->reject(fn ($f) => in_array($f->getFilename(), $allowed, true))
        ->filter(fn ($f) => str_contains($f->getContents(), 'rounded-full'))
        ->map->getFilename();

    expect($offenders)->toBeEmpty();
});

test('the brand red appears only in the logo mark', function () {
    $offenders = componentViews()
        ->reject(fn ($f) => $f->getFilename() === 'app-logo-icon.blade.php')
        ->filter(fn ($f) => preg_match('/(?:bg|text|border|ring|fill|stroke|from|via|to|outline|decoration)-brand/', $f->getContents()))
        ->map->getFilename();

    expect($offenders)->toBeEmpty();
});
