<?php

use App\Http\Controllers\Reader\StreamContentController;
use Illuminate\Support\Facades\Route;

// The file itself, for the reader's own browser: signed (10 minutes) and bound to the signed-in reader. pdf.js asks for many ranges, hence the roomy limiter.
Route::get('reader/stream/{source}', StreamContentController::class)
    ->middleware(['auth', 'verified', 'reader', 'signed:relative', 'throttle:reader-stream'])
    ->whereNumber('source')
    ->name('reader.stream');
