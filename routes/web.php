<?php

use App\Http\Controllers\CoverController;
use App\Http\Controllers\ExportDownloadController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LibraryController;
use App\Library\ExportStorage;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingController::class, 'show'])->name('landing');
Route::post('start', [LandingController::class, 'start'])->middleware('throttle:20,1')->name('landing.start');

// Public on purpose: the landing page's three.js book textures a cover for guests too. Still not an open proxy (see the controller).
Route::get('covers/{media}', [CoverController::class, 'show'])->middleware('throttle:120,1')->name('covers.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('home', 'pages::home.index')->name('home');
    Route::livewire('search', 'pages::search.index')->name('search');
    Route::livewire('discover', 'pages::discover.index')->name('discover');
    Route::livewire('explore', 'pages::explore.index')->name('explore.index');
    Route::livewire('explore/{code}', 'pages::explore.show')->name('explore.show')->where('code', '[0-9]{3}(\.[0-9]+)?');
    Route::livewire('stories/{slug}', 'pages::stories.show')->name('stories.show');
    Route::livewire('series/{slug}', 'pages::series.show')->name('series.show');
    Route::livewire('library', 'pages::library.index')->name('library.index');
    Route::livewire('collections', 'pages::collections.index')->name('collections.index');
    Route::livewire('collections/{slug}', 'pages::collections.show')->name('collections.show')->where('slug', '[a-z0-9-]+');
    Route::livewire('history', 'pages::history.index')->name('history.index');

    // The no-JS way to add a story; the pages add through the `library-add` browser event (see <livewire:library-listener>). Own throttle bucket, so it shares nothing with the other numeric throttles.
    Route::post('library', [LibraryController::class, 'store'])->middleware('throttle:60,1,library')->name('library.store');
});

// A reader's data export: signed (24 hours), their own account only. The file name is a ULID by the route, so it can never be a path. Its own throttle bucket, like library.store.
Route::get('exports/{user}/{file}', [ExportDownloadController::class, 'show'])
    ->middleware(['auth', 'signed', 'throttle:30,1,exports'])
    ->where(['user' => '[0-9]+', 'file' => ExportStorage::FILE_PATTERN])
    ->name('exports.download');

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
