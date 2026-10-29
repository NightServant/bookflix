<?php

use App\Http\Controllers\CoverController;
use App\Http\Controllers\LandingController;
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
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
