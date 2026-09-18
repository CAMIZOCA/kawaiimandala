<?php

use App\Http\Controllers\ActivepiecesCallbackController;
use Illuminate\Support\Facades\Route;

// Result of one generated mandala, sent by the Activepieces flow (protected by a per-request secret).
Route::post('/activepieces/books/{uuid}/mandalas/{position}', [ActivepiecesCallbackController::class, 'store'])
    ->whereNumber('position')
    ->middleware('throttle:120,1')
    ->name('api.activepieces.mandala');

// Same callback with `position` inside the JSON body.
Route::post('/activepieces/books/{uuid}/mandalas', [ActivepiecesCallbackController::class, 'store'])
    ->middleware('throttle:120,1')
    ->name('api.activepieces.mandalas');
