<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'can:access-admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::livewire('/', 'pages::admin.index')->name('index');
        Route::livewire('providers', 'pages::admin.providers.index')->name('providers');
        Route::livewire('media', 'pages::admin.media.index')->name('media.index');
        Route::livewire('media/{media}', 'pages::admin.media.edit')->name('media.edit');
        Route::livewire('duplicates', 'pages::admin.duplicates.index')->name('duplicates');
    });
