<?php

use App\Http\Controllers\Api\CashMovementController;
use App\Http\Controllers\Api\PosSessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('pos-sessions')->group(function () {
    Route::get('/current', [PosSessionController::class, 'current'])
        ->middleware('warehouse_context');
    Route::post('/open', [PosSessionController::class, 'open'])
        ->middleware(['role:admin,manager,cashier', 'warehouse_context']);
    Route::post('/close', [PosSessionController::class, 'close'])
        ->middleware(['role:admin,manager,cashier', 'warehouse_context']);

    // Cash movements for the current open session
    Route::post('/current/movements', [CashMovementController::class, 'store'])
        ->middleware('role:admin,manager,cashier');
    Route::get('/current/movements', [CashMovementController::class, 'index']);

    Route::get('/{posSession}', [PosSessionController::class, 'show'])
        ->middleware('role:admin,manager,cashier');
    Route::get('/', [PosSessionController::class, 'index']) // History of sessions
        ->middleware('role:admin,manager,cashier');
});
