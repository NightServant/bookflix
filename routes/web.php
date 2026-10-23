<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('home') : view('welcome'))->name('landing');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('home', 'pages::home.index')->name('home');
});

require __DIR__.'/settings.php';
