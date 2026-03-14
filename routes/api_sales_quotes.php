<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SalesQuoteController;

Route::middleware(['web'])->group(function () {
    Route::get('/sales-quotes',                      [SalesQuoteController::class, 'index']);
    Route::post('/sales-quotes',                     [SalesQuoteController::class, 'store']);
    Route::post('/sales-quotes/from-pos',            [SalesQuoteController::class, 'storeFromPos']);
    Route::get('/sales-quotes/{id}',                 [SalesQuoteController::class, 'show']);
    Route::put('/sales-quotes/{id}',                 [SalesQuoteController::class, 'update']);
    Route::delete('/sales-quotes/{id}',              [SalesQuoteController::class, 'destroy']);
    Route::post('/sales-quotes/{id}/convert',        [SalesQuoteController::class, 'convert']);
    Route::post('/sales-quotes/{id}/send-to-deposit',[SalesQuoteController::class, 'sendToDeposit']);
    Route::post('/sales-quotes/{id}/send-to-cashier',[SalesQuoteController::class, 'sendToCashier']);
    Route::post('/sales-quotes/{id}/mark-collected', [SalesQuoteController::class, 'markCollected']);
    Route::get('/sales-quotes/{id}/ticket',          [SalesQuoteController::class, 'ticket']);
    Route::get('/sales-quotes/{id}/proforma',        [SalesQuoteController::class, 'proforma']);
});
