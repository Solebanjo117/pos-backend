<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SalesQuoteController;

Route::middleware(['web'])->group(function () {
    Route::get('/sales-quotes',                      [SalesQuoteController::class, 'index']);
    Route::post('/sales-quotes',                     [SalesQuoteController::class, 'store'])
        ->middleware('role:admin,manager,seller');
    Route::post('/sales-quotes/from-pos',            [SalesQuoteController::class, 'storeFromPos'])
        ->middleware('role:admin,manager,seller,cashier');
    Route::get('/sales-quotes/pending-at-cashier',   [SalesQuoteController::class, 'pendingAtCashier'])
        ->middleware('role:admin,manager,cashier');
    Route::get('/sales-quotes/{id}',                 [SalesQuoteController::class, 'show']);
    Route::put('/sales-quotes/{id}',                 [SalesQuoteController::class, 'update'])
        ->middleware('role:admin,manager,seller');
    Route::delete('/sales-quotes/{id}',              [SalesQuoteController::class, 'destroy'])
        ->middleware('role:admin,manager');
    Route::post('/sales-quotes/{id}/convert',        [SalesQuoteController::class, 'convert'])
        ->middleware('role:admin,manager,cashier');
    Route::post('/sales-quotes/{id}/send-to-deposit',[SalesQuoteController::class, 'sendToDeposit'])
        ->middleware('role:admin,manager,seller');
    Route::post('/sales-quotes/{id}/send-to-cashier',[SalesQuoteController::class, 'sendToCashier'])
        ->middleware('role:admin,manager,seller');
    Route::delete('/sales-quotes/{id}/cashier-queue', [SalesQuoteController::class, 'removeFromCashierQueue'])
        ->middleware('role:admin,manager,cashier');
    Route::post('/sales-quotes/{id}/mark-collected', [SalesQuoteController::class, 'markCollected'])
        ->middleware('role:admin,manager,cashier');
    Route::get('/sales-quotes/{id}/ticket',          [SalesQuoteController::class, 'ticket']);
    Route::get('/sales-quotes/{id}/proforma',        [SalesQuoteController::class, 'proforma']);
});
