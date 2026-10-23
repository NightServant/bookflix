<?php

use App\Models\Media;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('home') : view('welcome'))->name('landing');

// ponytail: Phase 4 renders the story page here; until then only a merged-away slug resolves (301 to the winner).
Route::get('stories/{slug}', function (string $slug) {
    $media = Media::resolveSlug($slug);

    abort_if($media === null || $media->slug === $slug, 404);

    return redirect()->route('stories.show', $media->slug, 301);
})->name('stories.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('home', 'pages::home.index')->name('home');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
