<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('home') : view('welcome'))->name('landing');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('home', 'pages::home.index')->name('home');
    Route::livewire('search', 'pages::search.index')->name('search');
    Route::livewire('explore', 'pages::explore.index')->name('explore.index');
    Route::livewire('explore/{code}', 'pages::explore.show')->name('explore.show')->where('code', '[0-9]{3}(\.[0-9]+)?');
    Route::livewire('stories/{slug}', 'pages::stories.show')->name('stories.show');
    Route::livewire('series/{slug}', 'pages::series.show')->name('series.show');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
