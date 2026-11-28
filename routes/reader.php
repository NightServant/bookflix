<?php

use App\Http\Controllers\Reader\PageController;
use App\Http\Controllers\Reader\ProgressController;
use App\Http\Controllers\Reader\StreamContentController;
use App\Http\Middleware\LimitRequestBody;
use Illuminate\Support\Facades\Route;

// The file itself, for the reader's own browser: signed (10 minutes) and bound to the signed-in reader. pdf.js asks for many ranges, hence the roomy limiter.
Route::get('reader/stream/{source}', StreamContentController::class)
    ->middleware(['auth', 'verified', 'reader', 'signed:relative', 'throttle:reader-stream'])
    ->whereNumber('source')
    ->name('reader.stream');

// One page of a comic, streamed out of its zip: the same signature, user binding and limiter as the stream route.
Route::get('reader/{source}/page/{page}', PageController::class)
    ->middleware(['auth', 'verified', 'reader', 'signed:relative', 'throttle:reader-stream'])
    ->whereNumber(['source', 'page'])
    ->name('reader.page');

// The reader's place, saved while they read (and by a beacon as the tab closes): their own bucket, per person.
Route::post('reader/{source}/progress', ProgressController::class)
    ->middleware(['auth', 'verified', 'reader', 'throttle:reader-progress', LimitRequestBody::class.':8'])
    ->whereNumber('source')
    ->name('reader.progress');

// The reader itself: a full-screen page of its own, one engine per format.
Route::livewire('read/{slug}', 'pages::reader.show')
    ->middleware(['auth', 'verified', 'reader'])
    ->name('reader.show');
