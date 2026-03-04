<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PosSessionController;
use App\Http\Controllers\Api\CashMovementController;

Route::prefix('pos-sessions')->group(function () {
    Route::get('/current', [PosSessionController::class, 'current']);
    Route::post('/open', [PosSessionController::class, 'open']);
    Route::post('/close', [PosSessionController::class, 'close']);

    // Cash movements for the current open session
    Route::post('/current/movements', [CashMovementController::class, 'store']);
    Route::get('/current/movements', [CashMovementController::class, 'index']);

    Route::get('/{posSession}', [PosSessionController::class, 'show']);
    Route::get('/', [PosSessionController::class, 'index']); // History of sessions
});
