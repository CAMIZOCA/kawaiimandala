<?php

use App\Http\Controllers\ActivepiecesCallbackController;
use Illuminate\Support\Facades\Route;

// Result of one generated mandala, sent by the Activepieces flow (shared-secret protected).
Route::post('/activepieces/books/{uuid}/mandalas/{position}', [ActivepiecesCallbackController::class, 'store'])
    ->whereNumber('position')
    ->name('api.activepieces.mandala');

// Same callback with `position` inside the JSON body.
Route::post('/activepieces/books/{uuid}/mandalas', [ActivepiecesCallbackController::class, 'store'])
    ->name('api.activepieces.mandalas');
